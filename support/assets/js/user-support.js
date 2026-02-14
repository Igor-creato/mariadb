(function($) {
    'use strict';

    $(document).ready(function() {

        // ========= Переключение вкладок =========
        $('.cashback-support-tab').on('click', function() {
            var tab = $(this).data('tab');

            // Активируем вкладку
            $('.cashback-support-tab').removeClass('active');
            $(this).addClass('active');

            // Показываем содержимое
            $('.cashback-support-tab-content').hide().removeClass('active');
            $('#tab-' + tab).show().addClass('active');

            // Скрываем детали тикета при переключении вкладок
            $('#support-ticket-detail').hide();
        });

        // ========= Создание тикета =========
        $('#support-create-form').on('submit', function(e) {
            e.preventDefault();

            var subject = $('#support-subject').val().trim();
            var priority = $('#support-priority').val();
            var message = $('#support-message').val().trim();

            if (!subject || !message) {
                showAlert('support-create-alert', 'error', 'Заполните все обязательные поля');
                return;
            }

            var btn = $('#support-submit-btn');
            btn.prop('disabled', true).text('Отправка...');

            $.post(cashback_support.ajax_url, {
                action: 'support_create_ticket',
                nonce: cashback_support.create_nonce,
                subject: subject,
                priority: priority,
                message: message
            }, function(response) {
                if (response.success) {
                    showAlert('support-create-alert', 'success', response.data.message);
                    $('#support-create-form')[0].reset();
                    // Через 2 секунды переключаемся на вкладку истории и добавляем тикет
                    setTimeout(function() {
                        $('.cashback-support-tab[data-tab="history"]').click();
                        if (response.data.ticket_html) {
                            var $history = $('#tab-history');
                            // Убираем сообщение "У вас пока нет тикетов"
                            $history.find('p').filter(function() {
                                return $(this).text().indexOf('У вас пока нет тикетов') !== -1;
                            }).remove();
                            var $list = $('#support-tickets-list');
                            if ($list.length) {
                                $list.prepend(response.data.ticket_html);
                            } else {
                                $history.append('<div id="support-tickets-list">' + response.data.ticket_html + '</div><div id="support-pagination"></div>');
                            }
                            initPagination();
                        }
                        btn.prop('disabled', false).text('Отправить');
                    }, 2000);
                } else {
                    showAlert('support-create-alert', 'error', response.data.message || 'Ошибка при отправке, попробуйте еще раз');
                    btn.prop('disabled', false).text('Отправить');
                }
            }).fail(function() {
                showAlert('support-create-alert', 'error', 'Ошибка при отправке, попробуйте еще раз');
                btn.prop('disabled', false).text('Отправить');
            });
        });

        // ========= Загрузка тикета (клик по строке) =========
        $(document).on('click', '.support-ticket-row', function() {
            var $row = $(this);
            var ticketId = $row.data('ticket-id');
            // Снимаем выделение непрочитанного и убираем бейдж
            $row.removeClass('has-unread');
            $row.find('.support-unread-badge').remove();
            loadTicket(ticketId);
        });

        // ========= Назад к списку =========
        $(document).on('click', '#support-back-to-list', function(e) {
            e.preventDefault();
            $('#support-ticket-detail').hide();
            $('#tab-history').show();
            $('.cashback-support-tabs').show();
        });

        // ========= Ответ пользователя =========
        $(document).on('click', '#support-reply-btn', function() {
            var ticketId = $(this).data('ticket-id');
            var message = $('#support-reply-message').val().trim();

            if (!message) {
                showAlert('support-detail-alert', 'error', 'Введите текст сообщения');
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).text('Отправка...');

            $.post(cashback_support.ajax_url, {
                action: 'support_user_reply',
                nonce: cashback_support.reply_nonce,
                ticket_id: ticketId,
                message: message
            }, function(response) {
                if (response.success) {
                    $('#support-messages-list').append(response.data.html);
                    $('#support-reply-message').val('');
                    showAlert('support-detail-alert', 'success', 'Сообщение отправлено');
                } else {
                    showAlert('support-detail-alert', 'error', response.data.message || 'Ошибка при отправке, попробуйте еще раз');
                }
                btn.prop('disabled', false).text('Ответить');
            }).fail(function() {
                showAlert('support-detail-alert', 'error', 'Ошибка при отправке, попробуйте еще раз');
                btn.prop('disabled', false).text('Ответить');
            });
        });

        // ========= Закрытие тикета =========
        $(document).on('click', '#support-close-btn', function() {
            var btn = $(this);
            var ticketId = btn.data('ticket-id');
            btn.prop('disabled', true).text('Закрытие...');

            $.post(cashback_support.ajax_url, {
                action: 'support_user_close_ticket',
                nonce: cashback_support.close_nonce,
                ticket_id: ticketId
            }, function(response) {
                if (response.success) {
                    showAlert('support-detail-alert', 'success', 'Тикет закрыт');
                    // Обновляем бейдж статуса на «Закрыт»
                    var $badge = $('#support-ticket-detail-content .support-badge-open, #support-ticket-detail-content .support-badge-answered');
                    $badge.removeClass('support-badge-open support-badge-answered').addClass('support-badge-closed').text('Закрыт');
                    // Убираем форму ответа и кнопки действий
                    $('.support-ticket-actions').remove();
                    // Обновляем строку в списке тикетов
                    var $row = $('.support-ticket-row[data-ticket-id="' + ticketId + '"]');
                    $row.find('.support-badge-open, .support-badge-answered')
                        .removeClass('support-badge-open support-badge-answered')
                        .addClass('support-badge-closed').text('Закрыт');
                } else {
                    showAlert('support-detail-alert', 'error', response.data.message || 'Ошибка при выполнении');
                    btn.prop('disabled', false).text('Закрыть тикет');
                }
            }).fail(function() {
                showAlert('support-detail-alert', 'error', 'Ошибка при выполнении');
                btn.prop('disabled', false).text('Закрыть тикет');
            });
        });

        // ========= Пагинация истории тикетов =========
        var TICKETS_PER_PAGE = 10;
        var currentPage = 1;

        function initPagination() {
            var $rows = $('#support-tickets-list .support-ticket-row');
            var totalRows = $rows.length;
            var totalPages = Math.ceil(totalRows / TICKETS_PER_PAGE);
            var $pagination = $('#support-pagination');

            if (totalRows <= TICKETS_PER_PAGE) {
                $pagination.empty();
                $rows.show();
                return;
            }

            currentPage = 1;
            renderPagination(totalPages);
            showPage(1, $rows, totalPages);
        }

        function renderPagination(totalPages) {
            var $pagination = $('#support-pagination');
            $pagination.html(
                '<button type="button" class="button" id="support-page-prev">&lsaquo; Назад</button>' +
                '<span class="support-page-info"><span id="support-page-current">1</span> / ' + totalPages + '</span>' +
                '<button type="button" class="button" id="support-page-next">Вперёд &rsaquo;</button>'
            );
        }

        function showPage(page, $rows, totalPages) {
            currentPage = page;
            var start = (page - 1) * TICKETS_PER_PAGE;
            var end = start + TICKETS_PER_PAGE;

            $rows.hide().slice(start, end).show();

            $('#support-page-current').text(page);
            $('#support-page-prev').prop('disabled', page <= 1);
            $('#support-page-next').prop('disabled', page >= totalPages);
        }

        $(document).on('click', '#support-page-prev', function() {
            if (currentPage > 1) {
                var $rows = $('#support-tickets-list .support-ticket-row');
                var totalPages = Math.ceil($rows.length / TICKETS_PER_PAGE);
                showPage(currentPage - 1, $rows, totalPages);
            }
        });

        $(document).on('click', '#support-page-next', function() {
            var $rows = $('#support-tickets-list .support-ticket-row');
            var totalPages = Math.ceil($rows.length / TICKETS_PER_PAGE);
            if (currentPage < totalPages) {
                showPage(currentPage + 1, $rows, totalPages);
            }
        });

        initPagination();

        // ========= Вспомогательные функции =========

        function loadTicket(ticketId, callback) {
            $('#tab-history').hide();
            $('.cashback-support-tabs').hide();
            $('#support-ticket-detail').show();
            $('#support-ticket-detail-content').html('<p>Загрузка...</p>');

            $.post(cashback_support.ajax_url, {
                action: 'support_load_ticket',
                nonce: cashback_support.load_nonce,
                ticket_id: ticketId
            }, function(response) {
                if (response.success) {
                    $('#support-ticket-detail-content').html(response.data.html);
                    updateMenuBadge(response.data.unread_total);
                    if (typeof callback === 'function') {
                        callback();
                    }
                } else {
                    showAlert('support-detail-alert', 'error', 'Ошибка загрузки тикета');
                    $('#support-ticket-detail-content').html('<p>Ошибка загрузки тикета</p>');
                }
            }).fail(function() {
                $('#support-ticket-detail-content').html('<p>Ошибка загрузки тикета</p>');
            });
        }

        function showAlert(containerId, type, message) {
            var cssClass = type === 'success' ? 'support-alert-success' : 'support-alert-error';
            var $container = $('#' + containerId);
            $container.html('<div class="support-alert ' + cssClass + '">' + escapeHtml(message) + '</div>').show();

            if (type === 'success') {
                setTimeout(function() {
                    $container.fadeOut();
                }, 5000);
            }
        }

        function updateMenuBadge(count) {
            var $style = $('#cashback-support-menu-badge-style');
            if (count > 0) {
                var css = '.woocommerce-MyAccount-navigation-link--cashback-support a::after {' +
                    "content: '" + parseInt(count) + "';" +
                    'display: inline-block; min-width: 18px; height: 18px; line-height: 18px;' +
                    'padding: 0 5px; border-radius: 50%; background: #f44336;' +
                    'color: #fff !important; font-size: 11px; font-weight: bold;' +
                    'text-align: center; margin-left: 6px; vertical-align: middle;}';
                if ($style.length) {
                    $style.html(css);
                } else {
                    $('head').append('<style id="cashback-support-menu-badge-style">' + css + '</style>');
                }
            } else {
                $style.remove();
            }
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }
    });

})(jQuery);
