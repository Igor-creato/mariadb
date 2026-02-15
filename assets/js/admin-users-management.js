jQuery(document).ready(function($) {
    // Обработка фильтра по статусу
    $('#filter-submit').on('click', function() {
        var status = $('#filter-status').val();
        var url = new URL(window.location);
        if (status) {
            url.searchParams.set('status', status);
            url.searchParams.delete('paged');
        } else {
            url.searchParams.delete('status');
            url.searchParams.delete('paged');
        }
        window.location.href = url.toString();
    });

    // Обработка клика по кнопке "Редактировать" (используем делегирование)
    $(document).on('click', '.edit-btn', function() {
        var row = $(this).closest('tr');
        var cells = row.find('.edit-field');

        cells.each(function() {
            var cell = $(this);
            var field = cell.data('field');
            var currentValue = cell.text().trim();

            // Сохраняем оригинальное значение в data-атрибуте
            cell.attr('data-original-value', currentValue);

            if (field === 'status') {
                // Для поля status создаем select
                var selectHtml = '<select class="edit-input" data-field="' + field + '">';
                selectHtml += '<option value="active"' + (currentValue === 'active' ? ' selected' : '') + '>active</option>';
                selectHtml += '<option value="noactive"' + (currentValue === 'noactive' ? ' selected' : '') + '>noactive</option>';
                selectHtml += '<option value="banned"' + (currentValue === 'banned' ? ' selected' : '') + '>banned</option>';
                selectHtml += '<option value="deleted"' + (currentValue === 'deleted' ? ' selected' : '') + '>deleted</option>';
                selectHtml += '</select>';
                cell.html(selectHtml);
            } else if (field === 'cashback_rate' || field === 'min_payout_amount') {
                // Для числовых полей создаем input с типом number
                var step = '0.01';
                var placeholder = field === 'cashback_rate' ? 'Ставка кэшбэка' : 'Мин. сумма';
                cell.html('<input type="number" step="' + step + '" class="edit-input" data-field="' + field + '" value="' + currentValue + '" placeholder="' + placeholder + '" />');
            } else {
                // Для остальных полей создаем input
                cell.html('<input type="text" class="edit-input" data-field="' + field + '" value="' + currentValue + '" />');
            }
        });

        row.find('.edit-btn').hide();
        row.find('.save-btn, .cancel-btn').show();
    });

    // Обработка клика по кнопке "Отмена" (используем делегирование)
    $(document).on('click', '.cancel-btn', function() {
        var row = $(this).closest('tr');
        resetRowToViewMode(row);
    });

    // Обработка клика по кнопке "Сохранить" (используем делегирование)
    $(document).on('click', '.save-btn', function() {
        var row = $(this).closest('tr');
        var userId = row.data('user-id');
        var changedData = {};

        // Собираем только измененные данные
        row.find('.edit-input').each(function() {
            var input = $(this);
            var field = input.data('field');
            var newValue = input.val().trim();

            // Получаем оригинальное значение из data-атрибута ячейки
            var cell = input.closest('.edit-field');
            var originalValue = cell.attr('data-original-value');

            if (originalValue === undefined) {
                originalValue = '';
            }

            // Проверяем, изменилось ли значение (сравниваем как строки)
            if (originalValue.trim() !== newValue) {
                changedData[field] = newValue;
            }
        });

        // Добавляем только необходимые данные для обновления
        var data = {
            'action': 'update_user_profile',
            'user_id': userId,
            'nonce': cashbackUsersData.updateNonce
        };

        // Добавляем только измененные поля
        Object.assign(data, changedData);

        // Проверяем, есть ли вообще изменения
        var hasChanges = Object.keys(changedData).length > 0;

        if (!hasChanges) {
            alert('Нет изменений для сохранения.');
            // Переключаем строку обратно в режим просмотра
            resetRowToViewMode(row);
            return;
        }

        // Валидация только измененных данных
        if (changedData.hasOwnProperty('cashback_rate')) {
            var cashbackRate = parseFloat(changedData['cashback_rate']);
            if (isNaN(cashbackRate) || cashbackRate < 0 || cashbackRate > 100) {
                alert('Ставка кэшбэка должна быть числом от 0 до 100');
                return;
            }
        }

        if (changedData.hasOwnProperty('min_payout_amount')) {
            var minPayoutAmount = parseFloat(changedData['min_payout_amount']);
            if (isNaN(minPayoutAmount) || minPayoutAmount < 0) {
                alert('Минимальная сумма выплаты должна быть положительным числом');
                return;
            }
        }

        if (changedData.hasOwnProperty('status')) {
            var status = changedData['status'];
            var allowedStatuses = ['active', 'noactive', 'banned', 'deleted'];
            if (allowedStatuses.indexOf(status) === -1) {
                alert('Недопустимый статус пользователя');
                return;
            }
        }

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                // Обновляем все значения в ячейках, используя полученные данные из базы
                row.find('.edit-field[data-field="cashback_rate"]').text(response.data.cashback_rate);
                row.find('.edit-field[data-field="min_payout_amount"]').text(response.data.min_payout_amount);
                row.find('.edit-field[data-field="status"]').text(response.data.status);
                row.find('.edit-field[data-field="ban_reason"]').text(response.data.ban_reason);
                row.find('.edit-field[data-field="banned_at"]').text(response.data.banned_at ? response.data.banned_at : '');

                // Переключаем строку в режим просмотра
                row.find('.edit-input').each(function() {
                    var cell = $(this).closest('.edit-field');
                    var field = $(this).data('field');
                    cell.text(response.data[field] || '');
                });

                row.find('.save-btn, .cancel-btn').hide();
                row.find('.edit-btn').show();

                // Показываем сообщение об успешном обновлении
                $('.wp-header-end').after('<div class="notice notice-success is-dismissible"><p>Профиль пользователя успешно обновлен.</p></div>');
                setTimeout(function() {
                    $('.notice-success').fadeOut().remove();
                }, 3000);
            } else {
                alert('Ошибка при обновлении профиля пользователя: ' + response.data.message);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            var status = jqXHR.status || 0;
            var errorMsg = 'Ошибка соединения при обновлении профиля пользователя';

            if (status === 403) {
                errorMsg = 'Ошибка 403: Доступ запрещён. Возможно, сессия истекла. Обновите страницу.';
            } else if (status === 500) {
                errorMsg = 'Ошибка 500: Внутренняя ошибка сервера. Обратитесь к администратору.';
            } else if (status === 0) {
                errorMsg = 'Нет соединения с сервером. Проверьте подключение к интернету.';
            } else if (textStatus === 'timeout') {
                errorMsg = 'Превышено время ожидания ответа от сервера.';
            } else {
                errorMsg = 'Ошибка HTTP ' + status + ': ' + (errorThrown || textStatus);
            }

            alert(errorMsg);
        });
    });

    // Сброс строки к режиму просмотра
    function resetRowToViewMode(row) {
        // Обновляем строку данными из базы данных
        var userId = row.data('user-id');
        var data = {
            'action': 'get_user_profile',
            'user_id': userId,
            'nonce': cashbackUsersData.getNonce
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                row.find('.edit-field[data-field="cashback_rate"]').text(response.data.cashback_rate);
                row.find('.edit-field[data-field="min_payout_amount"]').text(response.data.min_payout_amount);
                row.find('.edit-field[data-field="status"]').text(response.data.status);
                row.find('.edit-field[data-field="ban_reason"]').text(response.data.ban_reason);
                row.find('.edit-field[data-field="banned_at"]').text(response.data.banned_at ? response.data.banned_at : '');
            } else {
                // Если не удалось получить данные, восстанавливаем старые значения
                row.find('.edit-field').each(function() {
                    var cell = $(this);
                    var input = cell.find('.edit-input');
                    if (input.length > 0) {
                        var currentValue = input.val();
                        cell.text(currentValue);
                    }
                });
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            var status = jqXHR.status || 0;
            var errorMsg = 'Ошибка при загрузке данных пользователя';

            if (status === 403) {
                errorMsg = 'Ошибка 403: Доступ запрещён. Обновите страницу.';
            } else if (status === 500) {
                errorMsg = 'Ошибка 500: Внутренняя ошибка сервера.';
            } else if (status === 0) {
                errorMsg = 'Нет соединения с сервером.';
            } else if (textStatus === 'timeout') {
                errorMsg = 'Превышено время ожидания ответа.';
            } else {
                errorMsg = 'Ошибка HTTP ' + status + ': ' + (errorThrown || textStatus);
            }

            console.error(errorMsg);

            // Восстанавливаем старые значения
            row.find('.edit-field').each(function() {
                var cell = $(this);
                var input = cell.find('.edit-input');
                if (input.length > 0) {
                    var currentValue = input.val();
                    cell.text(currentValue);
                }
            });
        });

        row.find('.save-btn, .cancel-btn').hide();
        row.find('.edit-btn').show();
    }
});
