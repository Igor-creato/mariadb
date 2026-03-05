/**
 * Service Worker для браузерного расширения кэшбэк-сервиса.
 *
 * Управляет:
 * - Определением магазинов-партнёров по домену текущей вкладки
 * - Состоянием иконки (серая/красная/зелёная)
 * - Кешем списка магазинов
 * - Сообщениями от popup и content scripts
 */

// Импорт конфигурации и API (через importScripts для service worker)
importScripts('../utils/config.js', '../utils/api.js');

// ─── Константы ───

const ICON_STATES = {
    GRAY: 'gray',
    RED: 'red',
    GREEN: 'green',
};

const ALARM_REFRESH_STORES = 'refresh-stores';
const ALARM_CLEANUP_ACTIVATIONS = 'cleanup-activations';
const CURRENT_USER_KEY = 'current_user_id';

// ─── Кеширование текущего пользователя ───

async function getCachedUserId() {
    const data = await chrome.storage.session.get(CURRENT_USER_KEY);
    return data[CURRENT_USER_KEY] || null;
}

async function setCachedUserId(userId) {
    if (!userId) return;
    const prev = await getCachedUserId();
    await chrome.storage.session.set({ [CURRENT_USER_KEY]: userId });
    // При смене пользователя — очистить все активации предыдущего
    if (prev && prev !== userId) {
        await clearAllActivations();
    }
}

async function clearCachedUserId() {
    await chrome.storage.session.remove(CURRENT_USER_KEY);
}

async function clearAllActivations() {
    const all = await chrome.storage.session.get(null);
    const keys = Object.keys(all).filter(k => k.startsWith('activation_'));
    if (keys.length > 0) {
        await chrome.storage.session.remove(keys);
    }
}

// ─── Инициализация ───

chrome.runtime.onInstalled.addListener(async () => {
    // Загрузить список магазинов при установке
    try {
        await CashbackAPI.fetchStores(true);
    } catch (e) {
        console.warn('[Cashback] Failed to load stores on install:', e.message);
    }

    // Настроить периодическое обновление списка магазинов (каждые 6 часов)
    chrome.alarms.create(ALARM_REFRESH_STORES, { periodInMinutes: 360 });

    // Очистка устаревших активаций (каждые 5 минут)
    chrome.alarms.create(ALARM_CLEANUP_ACTIVATIONS, { periodInMinutes: 5 });
});

// ─── Alarms ───

chrome.alarms.onAlarm.addListener(async (alarm) => {
    if (alarm.name === ALARM_REFRESH_STORES) {
        try {
            await CashbackAPI.fetchStores(true);
        } catch (e) {
            console.warn('[Cashback] Failed to refresh stores:', e.message);
        }
    }

    if (alarm.name === ALARM_CLEANUP_ACTIVATIONS) {
        await cleanupExpiredActivations();
    }
});

// ─── Слушатели вкладок ───

chrome.tabs.onUpdated.addListener(async (tabId, changeInfo, tab) => {
    if (changeInfo.status === 'complete' && tab.url) {
        await updateIconForTab(tabId, tab.url);
    }
});

chrome.tabs.onActivated.addListener(async (activeInfo) => {
    try {
        const tab = await chrome.tabs.get(activeInfo.tabId);
        if (tab.url) {
            await updateIconForTab(activeInfo.tabId, tab.url);
        }
    } catch (e) {
        // Вкладка может быть уже закрыта
    }
});

// ─── Обработка сообщений ───

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    handleMessage(message, sender).then(sendResponse).catch((e) => {
        sendResponse({ error: e.message });
    });
    return true; // async response
});

async function handleMessage(message, sender) {
    switch (message.type) {
        case 'GET_STORE_INFO': {
            const domain = message.domain;
            const store = await CashbackAPI.findStoreByDomain(domain);
            if (!store) {
                return { store: null, activated: false };
            }
            const activation = await getActivationStatus(domain);
            return { store, activated: activation.activated, activation };
        }

        case 'ACTIVATE': {
            const result = await CashbackAPI.activateCashback(message.productId);
            // Сохраняем активацию с привязкой к текущему пользователю
            const domain = message.domain;
            if (domain) {
                const userId = await getCachedUserId();
                await saveActivation(domain, result, userId);
            }
            // Обновляем иконку текущей вкладки
            if (sender.tab) {
                await updateIconForTab(sender.tab.id, sender.tab.url);
            }
            return result;
        }

        case 'GET_PROFILE': {
            const profile = await CashbackAPI.fetchProfile();
            await setCachedUserId(profile.user_id);
            return profile;
        }

        case 'GET_TRANSACTIONS': {
            return CashbackAPI.fetchTransactions(message.page || 1, message.perPage || 5);
        }

        case 'CHECK_AUTH': {
            try {
                const profile = await CashbackAPI.fetchProfile();
                await setCachedUserId(profile.user_id);
                return { authenticated: true, profile };
            } catch (e) {
                await clearCachedUserId();
                await clearAllActivations();
                return { authenticated: false, profile: null };
            }
        }

        case 'REFRESH_STORES': {
            await CashbackAPI.fetchStores(true);
            return { success: true };
        }

        case 'GET_ICON_STATE': {
            const store = await CashbackAPI.findStoreByDomain(message.domain);
            if (!store) return { state: ICON_STATES.GRAY };
            const activation = await getActivationStatus(message.domain);
            return {
                state: activation.activated ? ICON_STATES.GREEN : ICON_STATES.RED,
                store,
                activation,
            };
        }

        default:
            return { error: 'Unknown message type' };
    }
}

// ─── Управление иконкой ───

async function updateIconForTab(tabId, url) {
    try {
        const domain = extractDomain(url);
        if (!domain) {
            await setIcon(tabId, ICON_STATES.GRAY);
            return;
        }

        const store = await CashbackAPI.findStoreByDomain(domain);
        if (!store) {
            await setIcon(tabId, ICON_STATES.GRAY);
            return;
        }

        // Проверяем активацию
        let activation = await getActivationStatus(domain);

        // Если есть активация, но не удалось проверить пользователя — разрешаем через API
        if (activation.needs_auth_check) {
            try {
                const profile = await CashbackAPI.fetchProfile();
                await setCachedUserId(profile.user_id);
                // Повторная проверка с кешированным user_id
                activation = await getActivationStatus(domain);
            } catch {
                // Не авторизован — очищаем всё
                await clearCachedUserId();
                await clearAllActivations();
                activation = { activated: false };
            }
        }

        if (activation.activated) {
            await setIcon(tabId, ICON_STATES.GREEN, '');
        } else {
            // Показываем значок кэшбэка
            const badgeText = extractCashbackPercent(store.cashback_value);
            await setIcon(tabId, ICON_STATES.RED, badgeText);

            // Пушим уведомление в content script
            let isAuthenticated = false;
            try {
                const profile = await CashbackAPI.fetchProfile();
                isAuthenticated = !!profile;
                if (profile) {
                    await setCachedUserId(profile.user_id);
                }
            } catch {
                // Не авторизован — покажем кнопку входа
            }

            try {
                await chrome.tabs.sendMessage(tabId, {
                    type: 'SHOW_NOTIFICATION',
                    store,
                    isAuthenticated,
                });
            } catch {
                // Content script ещё не загружен — он сам запросит через fallback
            }
        }
    } catch (e) {
        // При ошибке — серая иконка
        await setIcon(tabId, ICON_STATES.GRAY);
    }
}

async function setIcon(tabId, state, badgeText = '') {
    const ICON_COLORS = {
        gray:  { bg: '#6b7280', accent: '#9ca3af' },
        red:   { bg: '#e74c3c', accent: '#c0392b' },
        green: { bg: '#27ae60', accent: '#1e8449' },
    };

    try {
        // Динамическая генерация иконки через OffscreenCanvas
        const imageData = {};
        for (const size of [16, 32]) {
            const canvas = new OffscreenCanvas(size, size);
            const ctx = canvas.getContext('2d');
            const c = ICON_COLORS[state];
            const cx = size / 2;
            const cy = size / 2;
            const r = size * 0.42;

            ctx.clearRect(0, 0, size, size);

            // Внешний круг
            ctx.beginPath();
            ctx.arc(cx, cy, r, 0, Math.PI * 2);
            ctx.fillStyle = c.bg;
            ctx.fill();

            // Внутренний круг
            ctx.beginPath();
            ctx.arc(cx, cy, r * 0.78, 0, Math.PI * 2);
            ctx.fillStyle = c.accent;
            ctx.fill();

            // Буква "К"
            ctx.fillStyle = '#ffffff';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = `bold ${Math.round(size * 0.4)}px Arial, sans-serif`;
            ctx.fillText('К', cx, cy + 1);

            imageData[size] = ctx.getImageData(0, 0, size, size);
        }

        await chrome.action.setIcon({ tabId, imageData });

        if (badgeText) {
            await chrome.action.setBadgeText({ tabId, text: badgeText });
            await chrome.action.setBadgeBackgroundColor({
                tabId,
                color: state === ICON_STATES.RED ? '#e74c3c' : '#27ae60',
            });
        } else {
            await chrome.action.setBadgeText({ tabId, text: '' });
        }
    } catch (e) {
        // Tab may have been closed or OffscreenCanvas not available
        // Fallback to static icons
        try {
            await chrome.action.setIcon({
                tabId,
                path: {
                    16: `icons/icon-${state}-16.png`,
                    32: `icons/icon-${state}-32.png`,
                    48: `icons/icon-${state}-48.png`,
                    128: `icons/icon-${state}-128.png`,
                },
            });
        } catch {
            // Tab was closed
        }
    }
}

// ─── Статус активации ───

async function getActivationStatus(domain) {
    const cleanDomain = domain.replace(/^www\./i, '');
    const key = `activation_${cleanDomain}`;

    const data = await chrome.storage.session.get(key);
    const activation = data[key];

    if (!activation) {
        return { activated: false };
    }

    // Проверяем TTL
    const elapsed = Date.now() - activation.timestamp;
    if (elapsed > CASHBACK_CONFIG.ACTIVATION_TTL) {
        await chrome.storage.session.remove(key);
        return { activated: false };
    }

    // Проверяем принадлежность пользователю
    if (!activation.user_id) {
        // Legacy-запись без user_id — удаляем
        await chrome.storage.session.remove(key);
        return { activated: false };
    }

    const currentUserId = await getCachedUserId();

    if (currentUserId && activation.user_id !== currentUserId) {
        // Активация принадлежит другому пользователю
        return { activated: false };
    }

    if (!currentUserId) {
        // Не знаем текущего пользователя — нужна проверка через API
        const remainingMs = CASHBACK_CONFIG.ACTIVATION_TTL - elapsed;
        return {
            activated: true,
            needs_auth_check: true,
            activated_at: activation.activated_at,
            expires_at: activation.expires_at,
            click_id: activation.click_id,
            remaining_minutes: Math.ceil(remainingMs / 60000),
        };
    }

    const remainingMs = CASHBACK_CONFIG.ACTIVATION_TTL - elapsed;
    return {
        activated: true,
        activated_at: activation.activated_at,
        expires_at: activation.expires_at,
        click_id: activation.click_id,
        remaining_minutes: Math.ceil(remainingMs / 60000),
    };
}

async function saveActivation(domain, result, userId) {
    const cleanDomain = domain.replace(/^www\./i, '');
    const key = `activation_${cleanDomain}`;

    await chrome.storage.session.set({
        [key]: {
            timestamp: Date.now(),
            activated_at: result.expires_at
                ? new Date(Date.now()).toISOString()
                : null,
            expires_at: result.expires_at,
            click_id: result.click_id,
            redirect_url: result.redirect_url,
            user_id: userId || null,
        },
    });
}

async function cleanupExpiredActivations() {
    const all = await chrome.storage.session.get(null);
    const keysToRemove = [];

    for (const [key, value] of Object.entries(all)) {
        if (key.startsWith('activation_') && value.timestamp) {
            const elapsed = Date.now() - value.timestamp;
            if (elapsed > CASHBACK_CONFIG.ACTIVATION_TTL) {
                keysToRemove.push(key);
            }
        }
    }

    if (keysToRemove.length > 0) {
        await chrome.storage.session.remove(keysToRemove);
    }
}

// ─── Утилиты ───

function extractDomain(url) {
    try {
        const parsed = new URL(url);
        if (!['http:', 'https:'].includes(parsed.protocol)) return null;
        return parsed.hostname.replace(/^www\./i, '');
    } catch {
        return null;
    }
}

function extractCashbackPercent(value) {
    if (!value) return '';
    // Извлекаем число из строк вроде "до 7%", "до 81%", "388р."
    const match = value.match(/(\d+)\s*%/);
    if (match) return match[1] + '%';
    // Для сумм
    const matchSum = value.match(/(\d+)\s*р/);
    if (matchSum) return matchSum[1] + 'р';
    return '';
}
