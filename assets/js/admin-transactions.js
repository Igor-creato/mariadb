(function ($) {
    'use strict';

    var statusLabels = {
        waiting:   'В ожидании',
        completed: 'Подтверждена',
        declined:  'Отклонена',
        hold:      'Удержание',
        balance:   'Зачислена на баланс'
    };

    function escapeHtml(text) {
        var map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    function showSuccessNotice(message) {
        var notice = $('<div>').addClass('notice notice-success is-dismissible')
            .append($('<p>').text(message));
        $('.wp-header-end').after(notice);
        setTimeout(function () {
            notice.fadeOut(function () { $(this).remove(); });
        }, 3000);
    }

    function updateRowData(row, txData) {
        row.find('.edit-field[data-field="order_status"]').text(statusLabels[txData.order_status] || txData.order_status);
        row.find('.edit-field[data-field="sum_order"]').text(txData.sum_order);
        row.find('.edit-field[data-field="comission"]').text(txData.comission);
        row.find('.cashback-display').text(txData.cashback);
    }

    $(document).ready(function () {

        // --- Filters ---
        $('#filter-submit').on('click', function () {
            var status = $('#filter-status').val();
            var search = $('#filter-search').val();
            var url = new URL(window.location.href);

            if (status) {
                url.searchParams.set('status', status);
            } else {
                url.searchParams.delete('status');
            }

            if (search) {
                url.searchParams.set('search', search);
            } else {
                url.searchParams.delete('search');
            }

            url.searchParams.delete('paged');
            window.location.href = url.toString();
        });

        $('#filter-reset').on('click', function () {
            var url = new URL(window.location.href);
            url.searchParams.delete('status');
            url.searchParams.delete('search');
            url.searchParams.delete('paged');
            window.location.href = url.toString();
        });

        // Allow Enter key in search field
        $('#filter-search').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#filter-submit').click();
            }
        });

        // --- Edit ---
        $(document).on('click', '.edit-btn', function () {
            var row = $(this).closest('tr');
            var cells = row.find('.edit-field');

            cells.each(function () {
                var cell = $(this);
                var field = cell.data('field');
                var currentValue = cell.text().trim();

                cell.attr('data-original-value', currentValue);

                if (field === 'order_status') {
                    var selectHtml = '<select class="edit-input" data-field="order_status">';
                    var statuses = ['waiting', 'completed', 'declined', 'hold'];
                    for (var i = 0; i < statuses.length; i++) {
                        var s = statuses[i];
                        var selected = (statusLabels[s] === currentValue || s === currentValue) ? ' selected' : '';
                        selectHtml += '<option value="' + escapeHtml(s) + '"' + selected + '>' + escapeHtml(statusLabels[s]) + '</option>';
                    }
                    selectHtml += '</select>';
                    cell.html(selectHtml);
                } else if (field === 'sum_order' || field === 'comission') {
                    cell.html(
                        '<input type="number" step="0.01" min="0" class="edit-input" data-field="' +
                        escapeHtml(field) + '" value="' + escapeHtml(currentValue) + '" />'
                    );
                }
            });

            row.find('.edit-btn').hide();
            row.find('.save-btn, .cancel-btn').show();
        });

        // --- Save ---
        $(document).on('click', '.save-btn', function () {
            var row = $(this).closest('tr');
            var transactionId = row.data('transaction-id');
            var tab = row.data('tab');
            var changedData = {};

            row.find('.edit-input').each(function () {
                var input = $(this);
                var field = input.data('field');
                var newValue = input.val();
                var cell = input.closest('.edit-field');
                var originalValue = (cell.attr('data-original-value') || '').trim();

                if (field === 'order_status') {
                    if (statusLabels[newValue] !== originalValue && newValue !== originalValue) {
                        changedData[field] = newValue;
                    }
                } else if (originalValue !== newValue) {
                    changedData[field] = newValue;
                }
            });

            if (Object.keys(changedData).length === 0) {
                alert('Нет изменений для сохранения.');
                row.find('.save-btn, .cancel-btn').hide();
                row.find('.edit-btn').show();
                // Restore text from original values
                row.find('.edit-field').each(function () {
                    var cell = $(this);
                    var orig = cell.attr('data-original-value');
                    if (orig !== undefined && cell.find('.edit-input').length) {
                        cell.text(orig);
                    }
                });
                return;
            }

            // Client-side validation
            if (changedData.sum_order !== undefined) {
                var sumVal = parseFloat(changedData.sum_order);
                if (isNaN(sumVal) || sumVal < 0) {
                    alert('Сумма заказа должна быть неотрицательным числом.');
                    return;
                }
            }
            if (changedData.comission !== undefined) {
                var comVal = parseFloat(changedData.comission);
                if (isNaN(comVal) || comVal < 0) {
                    alert('Комиссия должна быть неотрицательным числом.');
                    return;
                }
            }

            var data = $.extend({
                action: 'update_transaction',
                transaction_id: transactionId,
                tab: tab,
                nonce: cashbackTransactionsData.updateNonce
            }, changedData);

            $.post(ajaxurl, data, function (response) {
                if (response.success) {
                    updateRowData(row, response.data.transaction_data);
                    showSuccessNotice('Транзакция успешно обновлена.');
                    row.find('.save-btn, .cancel-btn').hide();
                    row.find('.edit-btn').show();
                } else {
                    alert('Ошибка: ' + (response.data.message || 'Неизвестная ошибка'));
                }
            }).fail(function (jqXHR) {
                var errorMsg = 'Ошибка соединения';
                if (jqXHR.status === 403) {
                    errorMsg = 'Доступ запрещён. Обновите страницу.';
                } else if (jqXHR.status === 500) {
                    errorMsg = 'Ошибка сервера.';
                } else if (jqXHR.status) {
                    errorMsg = 'Ошибка соединения: HTTP ' + jqXHR.status;
                }
                alert(errorMsg);
            });
        });

        // --- Cancel ---
        $(document).on('click', '.cancel-btn', function () {
            var row = $(this).closest('tr');
            var transactionId = row.data('transaction-id');
            var tab = row.data('tab');

            $.post(ajaxurl, {
                action: 'get_transaction',
                transaction_id: transactionId,
                tab: tab,
                nonce: cashbackTransactionsData.getNonce
            }, function (response) {
                if (response.success) {
                    updateRowData(row, response.data);
                } else {
                    // Fallback: restore from original values
                    row.find('.edit-field').each(function () {
                        var cell = $(this);
                        var orig = cell.attr('data-original-value');
                        if (orig !== undefined) {
                            cell.text(orig);
                        }
                    });
                }
            }).fail(function () {
                row.find('.edit-field').each(function () {
                    var cell = $(this);
                    var orig = cell.attr('data-original-value');
                    if (orig !== undefined) {
                        cell.text(orig);
                    }
                });
            });

            row.find('.save-btn, .cancel-btn').hide();
            row.find('.edit-btn').show();
        });
    });
})(jQuery);
