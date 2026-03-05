/**
 * Popup script для браузерного расширения кэшбэк-сервиса.
 *
 * Управляет отображением:
 * - Экрана авторизации
 * - Баланса пользователя
 * - Блока кэшбэка (доступен / активирован / недоступен)
 * - Списка последних транзакций
 */

document.addEventListener('DOMContentLoaded', init);

// ─── Элементы DOM ───

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => document.querySelectorAll(selector);

const els = {
    loading: $('#loading'),
    screenAuth: $('#screen-auth'),
    screenMain: $('#screen-main'),
    btnLogin: $('#btn-login'),
    userName: $('#user-name'),
    balanceAvailable: $('#balance-available'),
    balancePending: $('#balance-pending'),
    balancePaid: $('#balance-paid'),
    cashbackSection: $('#cashback-section'),
    cashbackAvailable: $('#cashback-available'),
    cashbackActivated: $('#cashback-activated'),
    cashbackNone: $('#cashback-none'),
    storeName: $('#store-name'),
    cashbackValue: $('#cashback-value'),
    btnActivate: $('#btn-activate'),
    timerValue: $('#timer-value'),
    linkStores: $('#link-stores'),
    transactionsList: $('#transactions-list'),
    btnRefresh: $('#btn-refresh'),
};

let timerInterval = null;

// ─── Инициализация ───

async function init() {
    // Кнопка входа
    els.btnLogin.addEventListener('click', () => {
        chrome.tabs.create({ url: CASHBACK_CONFIG.LOGIN_URL });
    });

    // Ссылка на магазины
    els.linkStores.addEventListener('click', (e) => {
        e.preventDefault();
        chrome.tabs.create({ url: CASHBACK_CONFIG.STORES_URL });
    });

    // Кнопка активации
    els.btnActivate.addEventListener('click', handleActivate);

    // Кнопка обновления магазинов
    els.btnRefresh.addEventListener('click', handleRefresh);

    // Проверка авторизации
    try {
        const authResult = await sendMessage({ type: 'CHECK_AUTH' });

        if (!authResult.authenticated) {
            showScreen('auth');
            return;
        }

        // Авторизован — показываем основной экран
        showScreen('main');
        renderProfile(authResult.profile);

        // Загружаем данные параллельно
        const [storeInfo, transactions] = await Promise.all([
            getCurrentTabStoreInfo(),
            sendMessage({ type: 'GET_TRANSACTIONS', page: 1, perPage: 5 }),
        ]);

        renderCashbackSection(storeInfo);
        renderTransactions(transactions);
    } catch {
        showScreen('auth');
    }
}

// ─── Экраны ───

function showScreen(screen) {
    els.loading.classList.add('hidden');
    els.screenAuth.classList.add('hidden');
    els.screenMain.classList.add('hidden');

    switch (screen) {
        case 'auth':
            els.screenAuth.classList.remove('hidden');
            break;
        case 'main':
            els.screenMain.classList.remove('hidden');
            break;
    }
}

// ─── Профиль и баланс ───

function renderProfile(profile) {
    els.userName.textContent = profile.display_name;
    els.balanceAvailable.textContent = formatMoney(profile.balance.available);
    els.balancePending.textContent = formatMoney(profile.balance.pending);
    els.balancePaid.textContent = formatMoney(profile.balance.paid);
}

// ─── Кэшбэк-секция ───

async function getCurrentTabStoreInfo() {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab || !tab.url) return null;

    try {
        const domain = new URL(tab.url).hostname.replace(/^www\./i, '');
        return sendMessage({ type: 'GET_STORE_INFO', domain });
    } catch {
        return null;
    }
}

function renderCashbackSection(storeInfo) {
    els.cashbackSection.classList.remove('hidden');

    // Скрываем все состояния
    els.cashbackAvailable.classList.add('hidden');
    els.cashbackActivated.classList.add('hidden');
    els.cashbackNone.classList.add('hidden');

    if (!storeInfo || !storeInfo.store) {
        // Нет кэшбэка на этом сайте
        els.cashbackNone.classList.remove('hidden');
        return;
    }

    if (storeInfo.activated) {
        // Кэшбэк активирован
        els.cashbackActivated.classList.remove('hidden');
        startTimer(storeInfo.activation);
    } else {
        // Кэшбэк доступен, но не активирован
        els.cashbackAvailable.classList.remove('hidden');
        els.storeName.textContent = storeInfo.store.store_name || storeInfo.store.domain;
        els.cashbackValue.textContent =
            (storeInfo.store.cashback_label || 'Кэшбэк') + ' ' +
            (storeInfo.store.cashback_value || '');
        els.btnActivate.dataset.productId = storeInfo.store.product_id;
        els.btnActivate.dataset.domain = storeInfo.store.domain;
    }
}

// ─── Обновление списка магазинов ───

async function handleRefresh() {
    const btn = els.btnRefresh;
    if (btn.classList.contains('spinning')) return;

    btn.classList.add('spinning');

    try {
        await sendMessage({ type: 'REFRESH_STORES' });

        // Перезагрузить информацию о текущем магазине
        const storeInfo = await getCurrentTabStoreInfo();
        renderCashbackSection(storeInfo);
    } catch {
        // Ошибка обновления — игнорируем
    } finally {
        btn.classList.remove('spinning');
    }
}

// ─── Активация кэшбэка ───

async function handleActivate() {
    const btn = els.btnActivate;
    const productId = parseInt(btn.dataset.productId, 10);
    const domain = btn.dataset.domain;

    if (!productId || btn.disabled) return;

    btn.disabled = true;
    btn.classList.add('loading');
    btn.textContent = 'Активация...';

    try {
        const result = await sendMessage({
            type: 'ACTIVATE',
            productId,
            domain,
        });

        if (result.error) {
            throw new Error(result.error);
        }

        // Открываем redirect URL в текущей вкладке
        const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
        if (tab && result.redirect_url && isValidRedirectUrl(result.redirect_url)) {
            await chrome.tabs.update(tab.id, { url: result.redirect_url });
        }

        // Показываем активированное состояние
        els.cashbackAvailable.classList.add('hidden');
        els.cashbackActivated.classList.remove('hidden');
        startTimer({
            remaining_minutes: 30,
            activated_at: new Date().toISOString(),
        });
    } catch {
        btn.textContent = 'Ошибка. Попробуйте снова';
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
    }
}

// ─── Таймер активации ───

function startTimer(activation) {
    if (timerInterval) {
        clearInterval(timerInterval);
    }

    const updateTimer = () => {
        let remainingMs;

        if (activation.remaining_minutes !== undefined) {
            // Пересчитываем на основе timestamp
            const activatedAt = activation.activated_at
                ? new Date(activation.activated_at).getTime()
                : Date.now();
            const elapsed = Date.now() - activatedAt;
            remainingMs = CASHBACK_CONFIG.ACTIVATION_TTL - elapsed;
        } else if (activation.expires_at) {
            remainingMs = new Date(activation.expires_at).getTime() - Date.now();
        } else {
            remainingMs = CASHBACK_CONFIG.ACTIVATION_TTL;
        }

        if (remainingMs <= 0) {
            els.timerValue.textContent = 'Истёк';
            clearInterval(timerInterval);
            // Переключаем на состояние "доступен"
            setTimeout(() => location.reload(), 1000);
            return;
        }

        const minutes = Math.floor(remainingMs / 60000);
        const seconds = Math.floor((remainingMs % 60000) / 1000);
        els.timerValue.textContent = `${minutes} мин ${seconds.toString().padStart(2, '0')} сек`;
    };

    updateTimer();
    timerInterval = setInterval(updateTimer, 1000);
}

// ─── Транзакции ───

function renderTransactions(data) {
    if (!data || !data.items || data.items.length === 0) {
        els.transactionsList.innerHTML =
            '<div class="transactions-empty">Нет транзакций</div>';
        return;
    }

    els.transactionsList.innerHTML = data.items
        .map((item) => {
            const statusClass = getStatusClass(item.order_status);
            const statusLabel = getStatusLabel(item.order_status);
            const date = formatDate(item.action_date || item.created_at);
            const amount = formatMoney(item.cashback);

            return `
                <div class="transaction-item">
                    <div class="transaction-left">
                        <span class="transaction-name" title="${escapeHtml(item.offer_name || item.partner || '')}">${escapeHtml(item.offer_name || item.partner || 'Покупка')}</span>
                        <span class="transaction-date">${date}</span>
                    </div>
                    <div class="transaction-right">
                        <span class="transaction-amount positive">+${amount}</span>
                        <span class="transaction-status ${statusClass}">${statusLabel}</span>
                    </div>
                </div>
            `;
        })
        .join('');
}

// ─── Утилиты ───

function sendMessage(message) {
    return chrome.runtime.sendMessage(message);
}

function formatMoney(amount) {
    const num = parseFloat(amount) || 0;
    return num.toLocaleString('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' ' + CASHBACK_CONFIG.CURRENCY_SYMBOL;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    try {
        const date = new Date(dateStr);
        return date.toLocaleDateString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        });
    } catch {
        return dateStr;
    }
}

function getStatusClass(status) {
    const map = {
        waiting: 'status-waiting',
        completed: 'status-completed',
        balance: 'status-balance',
        declined: 'status-declined',
        hold: 'status-hold',
    };
    return map[status] || 'status-waiting';
}

function getStatusLabel(status) {
    const map = {
        waiting: 'Ожидание',
        completed: 'Подтверждён',
        balance: 'Зачислен',
        declined: 'Отклонён',
        hold: 'Проверка',
    };
    return map[status] || status;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
