jQuery(document).ready(function ($) {
  // Обработчик для кнопки "Сохранить" в профиле пользователя
  $(document).on('click', '#save_payout_details_btn', function (e) {
    e.preventDefault();

    var payoutMethodId = $('#payout_method_id').val();
    var payoutAccount = $('#payout_account').val();
    var nonce = ajax_object.nonce; // Получаем nonce из локализованного объекта

    // Валидация
    if (!payoutMethodId) {
      $('#payout_details_message')
        .removeClass('success')
        .addClass('error')
        .text('Пожалуйста, выберите способ вывода');
      return;
    }

    if (!payoutAccount.trim()) {
      $('#payout_details_message')
        .removeClass('success')
        .addClass('error')
        .text('Пожалуйста, введите номер счета или телефона');
      return;
    }

    // Очищаем предыдущие сообщения
    $('#payout_details_message').text('').removeClass('success error');

    // Отправляем AJAX-запрос
    $.ajax({
      url: ajax_object.ajax_url,
      type: 'POST',
      data: {
        action: 'save_payout_details',
        payout_method_id: payoutMethodId,
        payout_account: payoutAccount,
        security: nonce,
      },
      beforeSend: function () {
        $('#save_payout_details_btn').prop('disabled', true).text('Сохранение...');
      },
      success: function (response) {
        if (response.success) {
          $('#payout_details_message')
            .removeClass('error')
            .addClass('success')
            .text(response.data.message);

          // Обновляем блок с текущими данными
          if (response.data.current_data_html) {
            const $currentDataBlock = $('.payout-current-data');

            if ($currentDataBlock.length) {
              // Если блок существует, добавляем класс для анимации
              $currentDataBlock.addClass('updating');

              // Через короткую задержку заменяем содержимое
              setTimeout(() => {
                $currentDataBlock.replaceWith(response.data.current_data_html);
              }, 150);
            } else {
              // Если блока нет, добавляем его после сообщения
              $('#payout_details_message').parent().after(response.data.current_data_html);
            }
          }
        } else {
          $('#payout_details_message')
            .removeClass('success')
            .addClass('error')
            .text(response.data.message || 'Ошибка при сохранении данных');
        }
      },
      error: function () {
        $('#payout_details_message')
          .removeClass('success')
          .addClass('error')
          .text('Ошибка соединения');
      },
      complete: function () {
        $('#save_payout_details_btn').prop('disabled', false).text('Сохранить');
      },
    });
  });

  // Также добавим валидацию при отправке всей формы
  $(document).on('submit', 'form.edit-account', function () {
    var payoutMethodId = $('#payout_method_id').val();
    var payoutAccount = $('#payout_account').val();

    if (!payoutMethodId) {
      $('#payout_details_message')
        .removeClass('success')
        .addClass('error')
        .text('Пожалуйста, выберите способ вывода');
      return false;
    }

    if (!payoutAccount.trim()) {
      $('#payout_details_message')
        .removeClass('success')
        .addClass('error')
        .text('Пожалуйста, введите номер счета или телефона');
      return false;
    }

    return true;
  });
});
