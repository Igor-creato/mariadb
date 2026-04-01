/**
 * Admin: Партнёрская программа
 */
(function ($) {
    'use strict';

    if (typeof cashbackAffiliateAdmin === 'undefined') {
        return;
    }

    var data = cashbackAffiliateAdmin;

    /* ── Module toggle ── */
    $(document).on('change', '#affiliate-module-toggle', function () {
        var enabled = $(this).is(':checked');

        $.post(data.ajaxurl, {
            action:  'affiliate_toggle_module',
            nonce:   data.toggleNonce,
            enabled: enabled ? 1 : 0
        }, function (resp) {
            if (resp.success) {
                location.reload();
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
            }
        });
    });

    /* ── Settings save ── */
    $(document).on('submit', '#affiliate-settings-form', function (e) {
        e.preventDefault();

        var $btn = $('#affiliate-save-settings').prop('disabled', true);

        $.post(data.ajaxurl, {
            action:     'affiliate_save_settings',
            nonce:      data.settingsNonce,
            global_rate: $('#aff-global-rate').val(),
            cookie_ttl:  $('#aff-cookie-ttl').val(),
            rules_url:   $('#aff-rules-url').val()
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp.success) {
                alert(resp.data.message);
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
            }
        });
    });

    /* ── Edit rate modal ── */
    $(document).on('click', '.aff-edit-rate', function () {
        var userId = $(this).data('user-id');
        var currentRate = $(this).data('rate');

        var overlay = $('<div class="aff-rate-modal-overlay">' +
            '<div class="aff-rate-modal">' +
            '<h3>Изменить ставку</h3>' +
            '<div class="aff-rate-input-group">' +
            '<input type="number" id="aff-modal-rate" min="0" max="100" step="0.01" placeholder="Глобальная" value="' + (currentRate || '') + '">' +
            '<span>%</span>' +
            '</div>' +
            '<p><small>Оставьте пустым для глобальной ставки.</small></p>' +
            '<div class="aff-rate-actions">' +
            '<button class="button aff-modal-cancel">Отмена</button>' +
            '<button class="button button-primary aff-modal-save">Сохранить</button>' +
            '</div></div></div>');

        $('body').append(overlay);

        overlay.on('click', '.aff-modal-cancel', function () {
            overlay.remove();
        });

        overlay.on('click', '.aff-modal-save', function () {
            var rate = $('#aff-modal-rate').val();

            $.post(data.ajaxurl, {
                action:         'affiliate_update_partner',
                nonce:          data.partnerNonce,
                user_id:        userId,
                partner_action: 'set_rate',
                rate:           rate
            }, function (resp) {
                overlay.remove();
                if (resp.success) {
                    location.reload();
                } else {
                    alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
                }
            });
        });

        overlay.on('click', function (e) {
            if ($(e.target).hasClass('aff-rate-modal-overlay')) {
                overlay.remove();
            }
        });
    });

    /* ── Disable partner ── */
    $(document).on('click', '.aff-disable-partner', function () {
        var userId = $(this).data('user-id');

        if (!confirm('Отключить партнёра от программы? Его партнёрские начисления будут заморожены.')) {
            return;
        }

        $.post(data.ajaxurl, {
            action:         'affiliate_update_partner',
            nonce:          data.partnerNonce,
            user_id:        userId,
            partner_action: 'disable'
        }, function (resp) {
            if (resp.success) {
                location.reload();
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
            }
        });
    });

    /* ── Enable partner ── */
    $(document).on('click', '.aff-enable-partner', function () {
        var userId = $(this).data('user-id');

        if (!confirm('Подключить партнёра обратно? Замороженные средства будут разморожены.')) {
            return;
        }

        $.post(data.ajaxurl, {
            action:         'affiliate_update_partner',
            nonce:          data.partnerNonce,
            user_id:        userId,
            partner_action: 'enable'
        }, function (resp) {
            if (resp.success) {
                location.reload();
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : 'Ошибка');
            }
        });
    });

})(jQuery);
