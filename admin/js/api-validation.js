/**
 * Cashback API Validation — Admin JS
 *
 * AJAX-обработчики:
 * - Кнопка «Проверить пользователя»
 * - Сохранение настроек API
 * - Ручной запуск синхронизации
 * - Загрузка лога синхронизации
 * - Inline-кнопки на странице выплат
 *
 * @since 5.0.0
 */

(function ($) {
    'use strict';

    const config = window.cashbackApiValidation || {};
    const i18n = config.i18n || {};

    // =========================================================================
    // Валидация пользователя (вкладка «Проверка»)
    // =========================================================================

    $(document).on('click', '#cashback-validate-btn', function () {
        const $btn = $(this);
        const userId = $('#cashback-validate-user-id').val();
        const network = $('#cashback-validate-network').val();
        const fullCheck = $('#cashback-validate-full').is(':checked');

        if (!userId || userId < 1) {
            alert('Укажите корректный User ID');
            return;
        }

        $btn.prop('disabled', true).text(i18n.validating || 'Проверка...');
        $('#cashback-validation-result').hide();

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_validate_user',
                nonce: config.nonce,
                user_id: userId,
                network: network,
                full_check: fullCheck ? 1 : 0,
            },
            success: function (response) {
                $btn.prop('disabled', false).text(i18n.validate || '🔍 Проверить пользователя');

                if (response.success && response.data) {
                    renderValidationResult(response.data);
                } else {
                    renderValidationError(response.data?.message || response.data?.error || 'Неизвестная ошибка');
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).text(i18n.validate || '🔍 Проверить пользователя');
                renderValidationError('Ошибка сети: ' + xhr.status + ' ' + xhr.statusText);
            },
        });
    });

    /**
     * Рендер результата валидации
     */
    function renderValidationResult(data) {
        const $result = $('#cashback-validation-result');
        let html = '';

        const isMatch = data.status === 'match';
        const statusClass = isMatch ? 'notice-success' : 'notice-warning';
        const statusText = isMatch
            ? (i18n.match || '✅ Данные совпадают')
            : (i18n.mismatch || '⚠️ Обнаружены расхождения');

        html += `<div class="notice ${statusClass}"><p><strong>${statusText}</strong></p></div>`;

        // Сводка
        html += '<table class="widefat fixed" style="max-width:700px;">';
        html += '<thead><tr><th colspan="2">Сводка проверки</th></tr></thead>';
        html += '<tbody>';
        html += `<tr><td>Пользователь</td><td><strong>#${data.user_id}</strong></td></tr>`;
        html += `<tr><td>Сеть</td><td>${escHtml(data.network)}</td></tr>`;
        html += `<tr><td>Период</td><td>${escHtml(data.date_range?.start || '')} — ${escHtml(data.date_range?.end || '')}</td></tr>`;
        html += `<tr><td>Действий в API</td><td>${data.api_total || 0}</td></tr>`;
        html += `<tr><td>Транзакций локально</td><td>${data.local_total || 0}</td></tr>`;
        html += `<tr><td>Совпадений</td><td style="color:green;">${data.matched_count || 0}</td></tr>`;
        html += `<tr><td>Расхождений</td><td style="color:${data.mismatch_count > 0 ? 'red' : 'green'};">${data.mismatch_count || 0}</td></tr>`;
        html += '</tbody></table>';

        // Финансовая сверка
        if (data.sums) {
            html += '<table class="widefat fixed" style="max-width:700px; margin-top:15px;">';
            html += '<thead><tr><th colspan="2">Финансовая сверка</th></tr></thead>';
            html += '<tbody>';
            html += `<tr><td>API approved</td><td>${formatMoney(data.sums.api_approved)}</td></tr>`;
            html += `<tr><td>API pending</td><td>${formatMoney(data.sums.api_pending)}</td></tr>`;
            html += `<tr><td>API declined</td><td>${formatMoney(data.sums.api_declined)}</td></tr>`;
            html += `<tr><td>Локальная сумма approved</td><td>${formatMoney(data.sums.local_approved)}</td></tr>`;
            html += `<tr><td>Локальная сумма pending</td><td>${formatMoney(data.sums.local_pending)}</td></tr>`;
            html += `<tr><td>Локальная сумма declined</td><td>${formatMoney(data.sums.local_declined)}</td></tr>`;

            const discStyle = data.sums.discrepancy > 0.01 ? 'color:red; font-weight:bold;' : 'color:green;';
            html += `<tr><td>Расхождение</td><td style="${discStyle}">${formatMoney(data.sums.discrepancy)}</td></tr>`;
            html += '</tbody></table>';
        }

        // ─── Вкладки с расхождениями ───
        const mismatchedCount = (data.mismatched || []).length;
        const missingLocalCount = (data.missing_local || []).length;
        const missingApiCount = (data.missing_api || []).length;
        const totalIssues = mismatchedCount + missingLocalCount + missingApiCount;

        if (totalIssues > 0) {
            // Навигация вкладок
            html += '<nav class="validation-result-tabs nav-tab-wrapper" style="margin-top:20px;">';
            html += `<a href="#" class="nav-tab nav-tab-active" data-tab="tab-mismatched">Расхождения <span class="tab-badge${mismatchedCount > 0 ? ' badge-red' : ''}">${mismatchedCount}</span></a>`;
            html += `<a href="#" class="nav-tab" data-tab="tab-missing-local">Есть в API, нет на сайте <span class="tab-badge${missingLocalCount > 0 ? ' badge-red' : ''}">${missingLocalCount}</span></a>`;
            html += `<a href="#" class="nav-tab" data-tab="tab-missing-api">Есть на сайте, нет в API <span class="tab-badge${missingApiCount > 0 ? ' badge-red' : ''}">${missingApiCount}</span></a>`;
            html += '</nav>';

            // Вкладка 1: Расхождения
            html += '<div class="validation-tab-content" id="tab-mismatched">';
            if (mismatchedCount > 0) {
                html += '<table class="widefat striped">';
                html += '<thead><tr><th>Action ID</th><th>Uniq ID</th><th>API статус</th><th>Локальный статус</th><th>API сумма</th><th>Локальная сумма</th><th>Проблема</th></tr></thead>';
                html += '<tbody>';
                data.mismatched.forEach(function (m) {
                    const problems = [];
                    if (m.status_mismatch) problems.push('статус');
                    if (m.commission_mismatch) problems.push('комиссия');
                    if (m.cart_mismatch) problems.push('сумма заказа');

                    const statusCls = m.status_mismatch ? ' class="cell-mismatch"' : '';
                    const sumCls = m.commission_mismatch ? ' class="cell-mismatch"' : '';

                    html += `<tr>
                        <td><code>${escHtml(m.action_id)}</code></td>
                        <td><code>${escHtml(m.uniq_id || m.click_id)}</code></td>
                        <td${statusCls}>${escHtml(m.api_status)} → ${escHtml(m.mapped_api_status)}</td>
                        <td${statusCls}>${escHtml(m.local_status)}</td>
                        <td${sumCls}>${formatMoney(m.api_payment)}</td>
                        <td${sumCls}>${formatMoney(m.local_commission)}</td>
                        <td style="color:red; font-weight:bold;">${problems.join(', ')}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            } else {
                html += '<p class="validation-empty">Нет расхождений в сопоставленных данных.</p>';
            }
            html += '</div>';

            // Вкладка 2: Есть в API, нет на сайте
            html += '<div class="validation-tab-content" id="tab-missing-local" style="display:none;">';
            if (missingLocalCount > 0) {
                html += '<table class="widefat striped">';
                html += '<thead><tr><th>Action ID</th><th>Order ID</th><th>Статус</th><th>Сумма</th><th>Дата</th><th>Магазин</th></tr></thead>';
                html += '<tbody>';
                data.missing_local.forEach(function (m) {
                    html += `<tr>
                        <td><code>${escHtml(m.action_id)}</code></td>
                        <td>${escHtml(m.order_id)}</td>
                        <td>${escHtml(m.status)}</td>
                        <td>${formatMoney(m.payment)}</td>
                        <td>${escHtml(m.date)}</td>
                        <td>${escHtml(m.campaign || '')}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            } else {
                html += '<p class="validation-empty">Все данные из API найдены в локальной базе.</p>';
            }
            html += '</div>';

            // Вкладка 3: Есть на сайте, нет в API
            html += '<div class="validation-tab-content" id="tab-missing-api" style="display:none;">';
            if (missingApiCount > 0) {
                html += '<table class="widefat striped">';
                html += '<thead><tr><th>Local ID</th><th>Uniq ID</th><th>Click ID</th><th>Статус</th><th>Комиссия</th><th>Создано</th></tr></thead>';
                html += '<tbody>';
                data.missing_api.forEach(function (m) {
                    html += `<tr>
                        <td>#${m.local_id}</td>
                        <td><code>${escHtml(m.uniq_id || '—')}</code></td>
                        <td><code>${escHtml(m.click_id || '—')}</code></td>
                        <td>${escHtml(m.status)}</td>
                        <td>${formatMoney(m.commission)}</td>
                        <td>${escHtml(m.created)}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            } else {
                html += '<p class="validation-empty">Все локальные транзакции найдены в API.</p>';
            }
            html += '</div>';
        }

        $result.html(html).fadeIn();
    }

    // Переключение вкладок результата
    $(document).on('click', '.validation-result-tabs .nav-tab', function (e) {
        e.preventDefault();
        const $tab = $(this);
        const targetId = $tab.data('tab');

        $tab.siblings('.nav-tab').removeClass('nav-tab-active');
        $tab.addClass('nav-tab-active');

        $tab.closest('#cashback-validation-result').find('.validation-tab-content').hide();
        $('#' + targetId).show();
    });

    /**
     * Рендер ошибки валидации
     */
    function renderValidationError(message) {
        const $result = $('#cashback-validation-result');
        $result.html(`<div class="notice notice-error"><p><strong>${i18n.error || '❌ Ошибка'}</strong>: ${escHtml(message)}</p></div>`);
        $result.fadeIn();
    }

    // =========================================================================
    // Сохранение настроек сети
    // =========================================================================

    $(document).on('click', '.cashback-save-network-btn', function () {
        const $btn = $(this);
        const $card = $btn.closest('.cashback-network-card');
        const networkId = $btn.data('network-id');
        const $status = $card.find('.cashback-save-status');

        const data = {
            action: 'cashback_save_api_credentials',
            nonce: config.nonce,
            network_id: networkId,
        };

        // Собираем все поля
        $card.find('.api-field').each(function () {
            data[$(this).attr('name')] = $(this).val();
        });

        // Credentials (только если заполнены)
        $card.find('.api-credential').each(function () {
            const name = $(this).attr('name');
            const val = $(this).val();
            if (val && !val.startsWith('•')) {
                data[name] = val;
            }
        });

        $btn.prop('disabled', true);
        $status.text(i18n.saving || 'Сохранение...').css('color', '#666');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: data,
            success: function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.text('✅ ' + (i18n.saved || 'Сохранено')).css('color', 'green');
                    // Очищаем поля credentials после сохранения
                    $card.find('.api-credential').val('');
                    $card.find('.api-credential[name="client_id"]').attr('placeholder', '••••••• (сохранён)');
                    $card.find('.api-credential[name="client_secret"]').attr('placeholder', '••••••• (сохранён)');
                } else {
                    $status.text('❌ ' + (response.data?.message || 'Ошибка')).css('color', 'red');
                }

                setTimeout(function () {
                    $status.text('');
                }, 5000);
            },
            error: function () {
                $btn.prop('disabled', false);
                $status.text('❌ Ошибка сети').css('color', 'red');
            },
        });
    });

    // =========================================================================
    // Ручная синхронизация
    // =========================================================================

    $(document).on('click', '#cashback-manual-sync-btn', function () {
        const $btn = $(this);
        const $status = $('#cashback-sync-status');

        if (!confirm(i18n.confirm_sync || 'Запустить синхронизацию статусов?')) {
            return;
        }

        $btn.prop('disabled', true);
        $status.text(i18n.syncing || 'Синхронизация...').css('color', '#666');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_manual_sync',
                nonce: config.nonce,
            },
            timeout: 120000, // 2 минуты таймаут
            success: function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.text('✅ ' + (i18n.sync_complete || 'Завершено')).css('color', 'green');
                    // Перезагружаем страницу для обновления блока статистики
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                } else {
                    $status.text('❌ ' + (response.data?.message || 'Ошибка')).css('color', 'red');
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false);
                $status.text('❌ Таймаут или ошибка сети').css('color', 'red');
            },
        });
    });

    // =========================================================================
    // Лог синхронизации
    // =========================================================================

    $(document).on('click', '#cashback-load-sync-log', function () {
        const $btn = $(this);
        const days = $('#cashback-sync-log-period').val();

        $btn.prop('disabled', true);

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_get_sync_log',
                nonce: config.nonce,
                days: days,
            },
            success: function (response) {
                $btn.prop('disabled', false);

                if (response.success && response.data.log) {
                    renderSyncLog(response.data.log);
                }
            },
            error: function () {
                $btn.prop('disabled', false);
            },
        });
    });

    function renderSyncLog(log) {
        const $table = $('#cashback-sync-log-table');
        const $tbody = $table.find('tbody');
        $tbody.empty();

        if (log.length === 0) {
            $tbody.append('<tr><td colspan="7" style="text-align:center;">Нет записей за выбранный период</td></tr>');
        } else {
            log.forEach(function (row) {
                const statusColor = row.new_status === 'completed' ? 'green' : row.new_status === 'declined' ? 'red' : '#666';
                $tbody.append(`<tr>
                    <td>${escHtml(row.synced_at)}</td>
                    <td>${escHtml(row.network_slug)}</td>
                    <td>#${row.transaction_id}${row.user_id ? ' (user ' + row.user_id + ')' : ''}</td>
                    <td><code>${escHtml(row.action_id || '')}</code></td>
                    <td>${escHtml(row.old_status)}</td>
                    <td style="color:${statusColor}; font-weight:bold;">${escHtml(row.new_status)}</td>
                    <td>${formatMoney(row.api_payment)}</td>
                </tr>`);
            });
        }

        $table.show();
    }

    // =========================================================================
    // Inline-кнопка валидации на странице выплат
    // =========================================================================

    $(document).on('click', '.cashback-inline-validate-btn', function () {
        const $btn = $(this);
        const userId = $btn.data('user-id');
        const $status = $(`.cashback-inline-validate-status[data-user-id="${userId}"]`);

        $btn.prop('disabled', true).text('⏳');
        $status.text('');

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'cashback_validate_user',
                nonce: config.nonce,
                user_id: userId,
                network: 'admitad',
                full_check: 0,
            },
            success: function (response) {
                $btn.prop('disabled', false).text('🔍 Проверить');

                if (response.success && response.data) {
                    const d = response.data;
                    if (d.status === 'match') {
                        $status.html('<span style="color:green;">✅ OK</span>');
                    } else {
                        $status.html(
                            `<span style="color:red;">⚠️ ${d.mismatch_count || 0} расх.</span>` +
                            (d.sums?.discrepancy > 0 ? ` <small>(${formatMoney(d.sums.discrepancy)})</small>` : '')
                        );
                    }
                } else {
                    $status.html('<span style="color:red;">❌ Ошибка</span>');
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('🔍 Проверить');
                $status.html('<span style="color:red;">❌ Ошибка сети</span>');
            },
        });
    });

    // =========================================================================
    // Утилиты
    // =========================================================================

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    function formatMoney(value) {
        if (value === null || value === undefined) return '—';
        return parseFloat(value).toFixed(2) + ' ₽';
    }

})(jQuery);
