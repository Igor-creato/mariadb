/**
 * Admin Payouts Management JavaScript
 *
 * @package WP_Cashback_Plugin
 */

(function ($) {
  'use strict';

  /**
   * Описания статусов выплат
   */
  const statusDescriptions = {
    waiting: 'Платеж еще не обрабатывался',
    processing: 'Платеж осуществляется',
    paid: 'Платеж выплачен',
    failed: 'Выплату невозможно осуществить по каким либо причинам',
    declined: 'Выплата заморожена из-за мошенничества',
    needs_retry: 'Выплата не прошла, попробовать повторить выплату',
  };

  /**
   * Метки статусов для отображения
   */
  const statusLabels = {
    waiting: 'Ожидает выплаты',
    processing: 'В обработке',
    paid: 'Выплачен',
    failed: 'Выплата не прошла',
    declined: 'Выплата заморожена',
    needs_retry: 'Проверить выплату',
  };

  /**
   * Экранирование HTML для предотвращения XSS
   *
   * @param {string} text - Текст для экранирования
   * @returns {string} Экранированный текст
   */
  function escapeHtml(text) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    };
    return String(text).replace(/[&<>"']/g, (m) => map[m]);
  }

  /**
   * Инициализация при загрузке DOM
   */
  $(document).ready(function () {
    console.log('Cashback Payouts Admin JS loaded');
    console.log('Edit buttons found:', $('.edit-btn').length);

    initFilters();
    initEditButtons();
    initCancelButtons();
    initSaveButtons();
  });

  /**
   * Инициализация фильтров
   */
  function initFilters() {
    // Обработка фильтра
    $('#filter-submit').on('click', function () {
      const status = $('#filter-status').val();
      const dateFrom = $('#filter-date-from').val();
      const dateTo = $('#filter-date-to').val();

      const url = new URL(window.location);

      if (status) {
        url.searchParams.set('status', status);
      } else {
        url.searchParams.delete('status');
      }

      if (dateFrom) {
        url.searchParams.set('date_from', dateFrom);
      } else {
        url.searchParams.delete('date_from');
      }

      if (dateTo) {
        url.searchParams.set('date_to', dateTo);
      } else {
        url.searchParams.delete('date_to');
      }

      url.searchParams.delete('paged');
      window.location.href = url.toString();
    });

    // Сброс фильтров
    $('#filter-reset').on('click', function () {
      const url = new URL(window.location);
      url.searchParams.delete('status');
      url.searchParams.delete('date_from');
      url.searchParams.delete('date_to');
      url.searchParams.delete('paged');
      window.location.href = url.toString();
    });
  }

  /**
   * Инициализация кнопок редактирования
   */
  function initEditButtons() {
    $('.edit-btn').on('click', function () {
      const row = $(this).closest('tr');
      const cells = row.find('.edit-field');

      cells.each(function () {
        const cell = $(this);
        const field = cell.data('field');
        const currentValue = cell.text().trim();

        cell.css('min-width', cell.width() + 'px');

        if (field === 'status') {
          createStatusSelect(cell, currentValue);
        } else if (field === 'attempts') {
          createNumberInput(cell, field, currentValue);
        } else {
          createTextInput(cell, field, currentValue);
        }
      });

      row.find('.edit-btn').hide();
      row.find('.save-btn, .cancel-btn').show();
    });
  }

  /**
   * Создание select для статуса
   *
   * @param {jQuery} cell - Ячейка таблицы
   * @param {string} currentValue - Текущее значение
   */
  function createStatusSelect(cell, currentValue) {
    const select = $('<select>').addClass('edit-input').attr('data-field', 'status').css({
      width: '100%',
      'box-sizing': 'border-box',
    });

    const statuses = ['waiting', 'processing', 'paid', 'failed', 'declined', 'needs_retry'];

    statuses.forEach(function (status) {
      const option = $('<option>').val(status).text(statusLabels[status]);

      if (currentValue === status || statusLabels[status] === currentValue) {
        option.prop('selected', true);
      }

      select.append(option);
    });

    cell.empty().append(select);
  }

  /**
   * Создание числового input
   *
   * @param {jQuery} cell - Ячейка таблицы
   * @param {string} field - Имя поля
   * @param {string} currentValue - Текущее значение
   */
  function createNumberInput(cell, field, currentValue) {
    const input = $('<input>')
      .attr('type', 'number')
      .attr('min', '0')
      .addClass('edit-input regular-text')
      .attr('data-field', field)
      .val(currentValue)
      .css({
        width: '100%',
        'box-sizing': 'border-box',
      });

    cell.empty().append(input);
  }

  /**
   * Создание текстового input
   *
   * @param {jQuery} cell - Ячейка таблицы
   * @param {string} field - Имя поля
   * @param {string} currentValue - Текущее значение
   */
  function createTextInput(cell, field, currentValue) {
    const input = $('<input>')
      .attr('type', 'text')
      .addClass('edit-input regular-text')
      .attr('data-field', field)
      .val(currentValue)
      .css({
        width: '100%',
        'box-sizing': 'border-box',
      });

    cell.empty().append(input);
  }

  /**
   * Инициализация кнопок отмены
   */
  function initCancelButtons() {
    $('.cancel-btn').on('click', function () {
      const row = $(this).closest('tr');
      resetRowToViewMode(row);
    });
  }

  /**
   * Инициализация кнопок сохранения
   */
  function initSaveButtons() {
    $('.save-btn').on('click', function () {
      const row = $(this).closest('tr');
      const payoutId = row.data('payout-id');
      const originalValues = {};
      const changedData = {};

      // Сохраняем оригинальные значения
      row.find('.edit-field').each(function () {
        const cell = $(this);
        const field = cell.data('field');
        originalValues[field] = cell.text().trim();
      });

      // Собираем измененные данные
      row.find('.edit-input').each(function () {
        const input = $(this);
        const field = input.data('field');
        const newValue = input.val();
        const originalValue = originalValues[field];

        if (originalValue !== newValue) {
          changedData[field] = newValue;
        }
      });

      if (Object.keys(changedData).length === 0) {
        alert('Нет изменений для сохранения.');
        row.find('.save-btn, .cancel-btn').hide();
        row.find('.edit-btn').show();
        return;
      }

      // Валидация
      if (changedData.hasOwnProperty('attempts')) {
        const attempts = parseInt(changedData['attempts']);
        if (isNaN(attempts) || attempts < 0) {
          alert('Количество попыток должно быть неотрицательным числом');
          return;
        }
      }

      if (changedData.hasOwnProperty('status')) {
        const status = changedData['status'];
        const allowedStatuses = [
          'waiting',
          'processing',
          'paid',
          'failed',
          'declined',
          'needs_retry',
        ];
        if (allowedStatuses.indexOf(status) === -1) {
          alert('Недопустимый статус выплаты');
          return;
        }
      }

      // Отправка AJAX запроса
      const data = {
        action: 'update_payout_request',
        payout_id: payoutId,
        nonce: cashbackPayoutsData.updateNonce,
        ...changedData,
      };

      $.post(ajaxurl, data, function (response) {
        if (response.success) {
          updateRowData(row, response.data.payout_data);
          updateStatusFilter(response.data.statuses);
          showSuccessNotice('Запрос на выплату успешно обновлен.');

          row.find('.save-btn, .cancel-btn').hide();
          row.find('.edit-btn').show();
        } else {
          alert(
            'Ошибка при обновлении запроса на выплату: ' +
              (response.data.message || 'Неизвестная ошибка'),
          );
        }
      }).fail(function () {
        alert('Ошибка соединения при обновлении запроса на выплату');
      });
    });
  }

  /**
   * Обновление данных строки
   *
   * @param {jQuery} row - Строка таблицы
   * @param {Object} payoutData - Данные выплаты
   */
  function updateRowData(row, payoutData) {
    row.find('.edit-field[data-field="provider"]').text(payoutData.provider || '');
    row
      .find('.edit-field[data-field="provider_payout_id"]')
      .text(payoutData.provider_payout_id || '');
    row.find('.edit-field[data-field="attempts"]').text(payoutData.attempts);
    row.find('.edit-field[data-field="fail_reason"]').text(payoutData.fail_reason || '');

    const statusText = payoutData.status;
    const statusLabel = statusLabels[statusText] || statusText;
    const statusDescription = statusDescriptions[statusText] || statusText;

    row.find('.edit-field[data-field="status"]').text(statusLabel).attr('title', statusDescription);

    row.find('.edit-field').css('min-width', '');
  }

  /**
   * Обновление фильтра статусов
   *
   * @param {Array} statuses - Массив статусов
   */
  function updateStatusFilter(statuses) {
    const statusFilter = $('#filter-status');
    const currentSelected = statusFilter.val();

    statusFilter.empty();
    statusFilter.append($('<option>').val('').text('Все статусы'));

    statuses.forEach(function (status) {
      const option = $('<option>')
        .val(status)
        .text(statusLabels[status] || status);

      if (status === currentSelected) {
        option.prop('selected', true);
      }

      statusFilter.append(option);
    });
  }

  /**
   * Показать уведомление об успехе
   *
   * @param {string} message - Сообщение
   */
  function showSuccessNotice(message) {
    const notice = $('<div>')
      .addClass('notice notice-success is-dismissible')
      .append($('<p>').text(message));

    $('.wp-header-end').after(notice);

    setTimeout(function () {
      notice.fadeOut(function () {
        $(this).remove();
      });
    }, 3000);
  }

  /**
   * Загрузка данных выплаты из базы
   *
   * @param {number} payoutId - ID выплаты
   * @param {Function} callback - Функция обратного вызова
   */
  function loadPayoutData(payoutId, callback) {
    const data = {
      action: 'get_payout_request',
      payout_id: payoutId,
      nonce: cashbackPayoutsData.getNonce,
    };

    $.post(ajaxurl, data, function (response) {
      if (response.success) {
        callback(null, response.data);
      } else {
        callback(response.data.message || 'Ошибка при загрузке данных выплаты', null);
      }
    }).fail(function () {
      callback('Ошибка соединения при загрузке данных выплаты', null);
    });
  }

  /**
   * Сброс строки к режиму просмотра
   *
   * @param {jQuery} row - Строка таблицы
   */
  function resetRowToViewMode(row) {
    const payoutId = row.data('payout-id');

    loadPayoutData(payoutId, function (error, payoutData) {
      if (error) {
        console.error('Ошибка загрузки данных выплаты:', error);
        alert('Ошибка загрузки данных выплаты: ' + error);
        return;
      }

      updateRowData(row, payoutData);

      row.find('.save-btn, .cancel-btn').hide();
      row.find('.edit-btn').show();
    });
  }
})(jQuery);
