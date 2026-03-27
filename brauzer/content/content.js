/**
 * Content script для браузерного расширения кэшбэк-сервиса.
 *
 * Показывает уведомление о кэшбэке на страницах магазинов-партнёров.
 * Два пути получения данных (для надёжности):
 *   1. Service Worker пушит SHOW_NOTIFICATION после определения магазина
 *   2. Content script сам запрашивает GET_STORE_INFO (fallback)
 *
 * Уведомление рендерится через Shadow DOM (изоляция от CSS сайта).
 */

(function () {
    'use strict';

    // Защита от повторной инжекции
    if (window.__cashbackExtInjected) return;
    window.__cashbackExtInjected = true;

    const domain = window.location.hostname.replace(/^www\./i, '');

    // Не показываем на нашем собственном сайте
    try {
        const siteHost = new URL(CASHBACK_CONFIG.SITE_URL).hostname.replace(/^www\./i, '');
        if (domain === siteHost) return;
    } catch {
        // ignore
    }

    // Не показываем на служебных страницах
    const proto = window.location.protocol;
    if (proto !== 'http:' && proto !== 'https:') return;

    let notificationShown = false;

    // ─── Путь 1: Получение push от Service Worker ───

    chrome.runtime.onMessage.addListener((message) => {
        if (message.type === 'SHOW_NOTIFICATION' && message.store && !notificationShown) {
            handleShowNotification(message.store, message.isAuthenticated);
        }
    });

    // ─── Путь 2: Самостоятельный запрос (fallback) ───

    setTimeout(async () => {
        if (notificationShown) return;

        try {
            const response = await chrome.runtime.sendMessage({
                type: 'GET_STORE_INFO',
                domain,
            });

            if (!response || !response.store) {
                return;
            }

            if (response.activated) {
                return;
            }

            // Если администратор выбрал «Не показывать» — уведомление не выводим
            if (response.store.popup_mode === 'hide') {
                return;
            }

            // Проверяем авторизацию отдельно (не блокирует показ)
            let isAuthenticated = false;
            try {
                const authResponse = await chrome.runtime.sendMessage({ type: 'CHECK_AUTH' });
                isAuthenticated = authResponse && authResponse.authenticated;
            } catch {
                // Покажем уведомление для неавторизованного
            }

            handleShowNotification(response.store, isAuthenticated);
        } catch {
            // Ошибка fallback-запроса — игнорируем
        }
    }, CASHBACK_CONFIG.NOTIFICATION_DELAY);

    // ─── Общая логика показа уведомления ───

    async function handleShowNotification(store, isAuthenticated) {
        if (notificationShown) return;

        // Проверяем dismiss
        try {
            const dismissKey = `dismissed_${domain}`;
            const dismissed = await chrome.storage.session.get(dismissKey);
            if (dismissed[dismissKey]) {
                return;
            }
        } catch {
            // Покажем всё равно
        }

        notificationShown = true;
        showNotification(store, domain, !!isAuthenticated);
    }

    // ─── Создание и показ уведомления через Shadow DOM ───

    function showNotification(store, domain, isAuthenticated) {
        const storeName = store.store_name || domain;
        const cashbackLabel = store.cashback_label || 'Кэшбэк';
        const cashbackValue = store.cashback_value || '';

        // Контейнер-хост для Shadow DOM
        const host = document.createElement('div');
        host.setAttribute('style',
            'all:initial !important;' +
            'position:fixed !important;' +
            'top:20px !important;' +
            'right:20px !important;' +
            'z-index:2147483647 !important;' +
            'display:block !important;' +
            'visibility:visible !important;' +
            'opacity:1 !important;' +
            'width:auto !important;' +
            'height:auto !important;' +
            'pointer-events:auto !important;'
        );

        const shadow = host.attachShadow({ mode: 'closed' });

        // Стили
        const style = document.createElement('style');
        style.textContent = getNotificationStyles();
        shadow.appendChild(style);

        // Уведомление
        const notification = document.createElement('div');
        notification.className = 'cb-notification';

        const safeProductId = parseInt(store.product_id, 10) || 0;
        const actionsHtml = isAuthenticated
            ? `<button class="cb-btn cb-btn-activate" data-product-id="${safeProductId}">Активировать кэшбэк</button>`
            : `<button class="cb-btn cb-btn-login">Войти и получить кэшбэк</button>`;

        const subtitleText = isAuthenticated
            ? 'Активируйте для получения возврата'
            : 'Войдите для получения кэшбэка';

        notification.innerHTML =
            '<div class="cb-header">' +
                '<span class="cb-brand">' +
                    '<span class="cb-brand-icon">&#128176;</span>' +
                    ' Кэшбэк Сервис' +
                '</span>' +
                '<button class="cb-close" title="Закрыть">&times;</button>' +
            '</div>' +
            '<div class="cb-body">' +
                '<span class="cb-icon">&#127873;</span>' +
                '<div class="cb-info">' +
                    '<span class="cb-title">' + escapeHtml(storeName) + '</span>' +
                    '<span class="cb-value">' + escapeHtml(cashbackLabel) + ' ' + escapeHtml(cashbackValue) + '</span>' +
                    '<span class="cb-subtitle">' + subtitleText + '</span>' +
                '</div>' +
            '</div>' +
            '<div class="cb-actions">' + actionsHtml + '</div>';

        shadow.appendChild(notification);

        // Вставляем в DOM
        const target = document.body || document.documentElement;
        target.appendChild(host);

        // Анимация появления
        requestAnimationFrame(() => {
            notification.classList.add('visible');
        });

        // ─── Обработчики ───

        shadow.querySelector('.cb-close').addEventListener('click', () => {
            dismissNotification(host, notification, domain);
        });

        const loginBtn = shadow.querySelector('.cb-btn-login');
        if (loginBtn) {
            loginBtn.addEventListener('click', () => {
                window.open(CASHBACK_CONFIG.LOGIN_URL, '_blank');
                dismissNotification(host, notification, domain);
            });
        }

        const activateBtn = shadow.querySelector('.cb-btn-activate');
        if (activateBtn) {
            activateBtn.addEventListener('click', async () => {
                const productId = parseInt(activateBtn.dataset.productId, 10);
                if (!productId || activateBtn.disabled) return;

                activateBtn.disabled = true;
                activateBtn.textContent = 'Активация...';

                try {
                    const result = await chrome.runtime.sendMessage({
                        type: 'ACTIVATE',
                        productId,
                        domain,
                    });

                    if (result && result.error) {
                        throw new Error(result.error);
                    }

                    activateBtn.textContent = '\u2713 Кэшбэк активирован!';
                    activateBtn.classList.add('success');
                    notification.classList.add('activated');

                    if (result && result.redirect_url && isValidRedirectUrl(result.redirect_url)) {
                        setTimeout(() => {
                            window.location.href = result.redirect_url;
                        }, 800);
                    } else {
                        setTimeout(() => dismissNotification(host, notification, domain), 3000);
                    }
                } catch (e) {
                    activateBtn.textContent = 'Ошибка. Попробуйте снова';
                    activateBtn.disabled = false;
                }
            });
        }
    }

    // ─── Скрытие уведомления ───

    function dismissNotification(host, notification, domain) {
        notification.classList.remove('visible');
        notification.classList.add('hiding');

        const dismissKey = `dismissed_${domain}`;
        chrome.storage.session.set({ [dismissKey]: true }).catch(() => {});

        setTimeout(() => host.remove(), 400);
    }

    // ─── Стили (инжектируются в Shadow DOM) ───

    function getNotificationStyles() {
        return `
            .cb-notification {
                width: 360px;
                max-width: calc(100vw - 40px);
                background: #1a1d23;
                border: 1px solid rgba(231, 76, 60, 0.4);
                border-radius: 14px;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5),
                            0 0 0 1px rgba(255, 255, 255, 0.05);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 14px;
                color: #e4e6ea;
                overflow: hidden;
                transform: translateY(-20px);
                opacity: 0;
                transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1),
                            opacity 0.3s ease;
            }
            .cb-notification.visible {
                transform: translateY(0);
                opacity: 1;
            }
            .cb-notification.hiding {
                transform: translateY(-20px);
                opacity: 0;
            }
            .cb-notification.activated {
                border-color: rgba(39, 174, 96, 0.4);
            }
            .cb-notification.activated .cb-value {
                color: #27ae60;
            }
            .cb-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 14px 0;
            }
            .cb-brand {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 11px;
                color: #6b6f80;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .cb-brand-icon { font-size: 14px; }
            .cb-close {
                width: 24px; height: 24px;
                display: flex; align-items: center; justify-content: center;
                background: none; border: none;
                color: #6b6f80; font-size: 18px;
                cursor: pointer; border-radius: 4px;
                transition: all 0.2s;
                line-height: 1; padding: 0;
            }
            .cb-close:hover {
                background: rgba(255, 255, 255, 0.1);
                color: #e4e6ea;
            }
            .cb-body {
                padding: 12px 14px;
                display: flex; align-items: center; gap: 12px;
            }
            .cb-icon {
                font-size: 36px; flex-shrink: 0; line-height: 1;
            }
            .cb-info {
                display: flex; flex-direction: column; gap: 2px; flex: 1;
            }
            .cb-title {
                font-size: 15px; font-weight: 600; color: #fff;
            }
            .cb-value {
                font-size: 18px; font-weight: 700; color: #e74c3c;
            }
            .cb-subtitle {
                font-size: 12px; color: #8b8fa3;
            }
            .cb-actions { padding: 0 14px 14px; }
            .cb-btn {
                display: flex; align-items: center; justify-content: center;
                width: 100%; padding: 11px 16px;
                color: #fff; border: none; border-radius: 10px;
                font-size: 14px; font-weight: 600;
                cursor: pointer; transition: all 0.2s ease;
                font-family: inherit; box-sizing: border-box;
            }
            .cb-btn-activate {
                background: linear-gradient(135deg, #e74c3c, #c0392b);
            }
            .cb-btn-activate:hover {
                background: linear-gradient(135deg, #c0392b, #a93226);
                transform: translateY(-1px);
            }
            .cb-btn-login {
                background: linear-gradient(135deg, #4f9cf7, #3b82d9);
            }
            .cb-btn-login:hover {
                background: linear-gradient(135deg, #3b82d9, #2c6fbd);
                transform: translateY(-1px);
            }
            .cb-btn:active { transform: translateY(0); }
            .cb-btn:disabled {
                opacity: 0.6; cursor: not-allowed; transform: none;
            }
            .cb-btn.success {
                background: linear-gradient(135deg, #27ae60, #1e8449);
            }
            @media (max-width: 420px) {
                .cb-notification { width: auto; max-width: none; }
            }
        `;
    }

    // ─── Утилиты ───

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Проверка redirect URL перед навигацией.
     * Блокирует javascript:, data: и другие небезопасные протоколы.
     */
    function isValidRedirectUrl(url) {
        try {
            const parsed = new URL(url);
            return parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch {
            return false;
        }
    }
})();
