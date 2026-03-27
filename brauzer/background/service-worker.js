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

    // Периодическое обновление списка магазинов (каждые 15 минут — как резервный механизм,
    // основной TTL кеша — 10 минут, данные обновляются при каждом визите)
    chrome.alarms.create(ALARM_REFRESH_STORES, { periodInMinutes: 15 });

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
                // Синхронизируем иконку: магазин не найден — серая
                if (sender.tab) {
                    await setIcon(sender.tab.id, ICON_STATES.GRAY);
                }
                return { store: null, activated: false };
            }
            const activation = await getActivationStatus(domain);
            // Синхронизируем иконку с актуальным состоянием
            if (sender.tab) {
                if (activation.activated) {
                    await setIcon(sender.tab.id, ICON_STATES.GREEN);
                } else {
                    const badgeText = extractCashbackPercent(store.cashback_value);
                    await setIcon(sender.tab.id, ICON_STATES.RED, badgeText);
                }
            }
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

        // Если кеш устарел — обновляем ДО поиска магазина, чтобы получить актуальный
        // popup_mode и другие настройки (изменения применяются сразу после обновления в админке)
        const cacheData = await chrome.storage.local.get('stores_updated_at');
        const cacheAge = Date.now() - (cacheData.stores_updated_at || 0);
        if (cacheAge > CASHBACK_CONFIG.STORES_CACHE_TTL) {
            try {
                await CashbackAPI.fetchStores(true);
            } catch {
                // Используем устаревший кеш если обновить не удалось
            }
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

            // Отправляем уведомление только если popup_mode !== 'hide'
            if (!store.popup_mode || store.popup_mode !== 'hide') {
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
        }
    } catch (e) {
        // При ошибке — серая иконка
        await setIcon(tabId, ICON_STATES.GRAY);
    }
}

async function setIcon(tabId, state, badgeText = '') {
    try {
        await chrome.action.setIcon({
            tabId,
            path: {
                16:  chrome.runtime.getURL(`icons/icon-${state}-16.png`),
                32:  chrome.runtime.getURL(`icons/icon-${state}-32.png`),
                48:  chrome.runtime.getURL(`icons/icon-${state}-48.png`),
                128: chrome.runtime.getURL(`icons/icon-${state}-128.png`),
            },
        });

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
        console.warn('[Cashback] setIcon error:', e && e.message);
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
