(function ($) {
  'use strict';

  $(document).ready(function () {
    console.log('WC Affiliate URL Params: Script loaded');
    console.log('WC Affiliate Params:', wcAffiliateParams);

    // Обработчик только для кнопок покупки с внешней партнерской ссылкой
    $(document).on(
      'click',
      'a.add_to_cart_button, a.single_add_to_cart_button, a.product_type_external, a.add-to-cart-loop',
      function (e) {
        const $button = $(this);
        let href = $button.attr('href') || $button.data('href');

        // Для кнопок без href ищем URL в контексте (форма, контейнер товара)
        if (!href) {
          // Сначала проверяем форму
          const $form = $button.closest('form');
          if ($form.length) {
            href =
              $form.find('input[name="product_url"]').val() ||
              $form.find('a[href*="USER_PLACEHOLDER_"]').attr('href') ||
              $form.data('product-url');
          }

          // Если не нашли в форме, ищем в контейнере товара
          if (!href) {
            href = $button
              .closest(
                '.product, .wd-quick-view, .quick-view-modal, .wd-popup, .single-product, .products, .product-grid, .wc-block-grid__products, .products-list',
              )
              .find('a[href*="USER_PLACEHOLDER_"]')
              .attr('href');
          }

          // Последний вариант - глобальный поиск
          if (!href) {
            href = $('a[href*="USER_PLACEHOLDER_"]').first().attr('href');
          }
        }

        if (!href) {
          console.log('WC Affiliate: No href found for purchase button, skipping');
          return; // Не наш элемент
        }

        console.log('WC Affiliate: Found purchase button with href:', href);

        // ПРОВЕРКА: содержит ли href USER_PLACEHOLDER_
        if (!href.includes('USER_PLACEHOLDER_')) {
          console.log(
            'WC Affiliate: Href does not contain USER_PLACEHOLDER_, skipping purchase button',
          );
          return; // Не наш элемент
        }

        console.log(
          'WC Affiliate: Intercepted purchase button with USER_PLACEHOLDER_:',
          href,
          $button,
        );

        // Проверяем авторизацию
        if (!wcAffiliateParams.isLoggedIn) {
          console.log('WC Affiliate: User not logged in, showing warning for purchase button');

          e.preventDefault();
          e.stopImmediatePropagation();
          showAuthWarning($button, href);
          return false;
        } else {
          console.log('WC Affiliate: User logged in, replacing placeholder in purchase button');
          // Если пользователь авторизован, заменяем плейсхолдер на ID
          const finalUrl = replaceUserPlaceholders(href, wcAffiliateParams.userId);
          $button.attr('href', finalUrl);
          // Продолжаем стандартное поведение
        }
      },
    );
  });

  /**
   * Показ предупреждения для неавторизованных пользователей
   */
  function showAuthWarning($button, originalUrl) {
    // Удаляем существующее модальное окно, если есть
    $('#wc-affiliate-warning-modal').remove();

    // Создаем модальное окно
    const modal = `
            <div id="wc-affiliate-warning-modal" class="wc-affiliate-modal">
                <div class="wc-affiliate-modal-content">
                    <span class="wc-affiliate-modal-close">&times;</span>
                    <div class="wc-affiliate-modal-icon">⚠️</div>
                    <h3 class="wc-affiliate-modal-title">Внимание</h3>
                    <p class="wc-affiliate-modal-message">${wcAffiliateParams.warningMessage}</p>
                    <div class="wc-affiliate-modal-actions">
                        <a href="${replaceUserPlaceholdersWithUnregistered(originalUrl)}"
                           class="wc-affiliate-btn wc-affiliate-btn-primary"
                           id="wc-affiliate-continue" target="_blank">
                            Продолжить без авторизации
                        </a>
                        <a href="${
                          wcAffiliateParams.loginUrl
                        }" class="wc-affiliate-btn wc-affiliate-btn-secondary" id="wc-affiliate-cancel">
                            Авторизоваться или зарегистрироваться
                        </a>
                    </div>
                </div>
            </div>
        `;

    $('body').append(modal);

    // Показываем модальное окно
    setTimeout(() => {
      $('#wc-affiliate-warning-modal').addClass('show');
    }, 10);

    // Обработчики закрытия
    $('#wc-affiliate-warning-modal').on('click', '.wc-affiliate-modal-close', function () {
      closeModal();
    });

    // Закрытие при клике вне окна
    $(window).on('click.wcAffiliateModal', function (e) {
      if ($(e.target).attr('id') === 'wc-affiliate-warning-modal') {
        closeModal();
      }
    });
  }

  /**
   * Закрытие модального окна
   */
  function closeModal() {
    $('#wc-affiliate-warning-modal').removeClass('show');
    setTimeout(() => {
      $('#wc-affiliate-warning-modal').remove();
      $(window).off('click.wcAffiliateModal'); // Удаляем обработчик, чтобы избежать дублирования
    }, 300);
  }

  /**
   * Замена placeholder на реальный ID пользователя
   */
  function replaceUserPlaceholders(url, userId) {
    return url.replace(/USER_PLACEHOLDER_\d+/g, userId);
  }

  /**
   * Замена параметров с placeholder на "unregistered" для кнопки "Продолжить"
   */
  function replaceUserPlaceholdersWithUnregistered(url) {
    try {
      const urlObj = new URL(url);
      const params = new URLSearchParams(urlObj.search);

      // Заменяем значения с USER_PLACEHOLDER_ на "unregistered"
      for (const [key, value] of params.entries()) {
        if (value.includes('USER_PLACEHOLDER_')) {
          params.set(key, 'unregistered');
        }
      }

      urlObj.search = params.toString();
      return urlObj.toString();
    } catch (e) {
      console.error('Error processing URL:', e);
      return url;
    }
  }
})(jQuery);

// Frontend JavaScript for the cashback withdrawal functionality
jQuery(document).ready(function ($) {
  // Обработчик отправки формы вывода кэшбэка
  $('#withdrawal-form').on('submit', function (e) {
    e.preventDefault(); // Предотвращаем стандартную отправку формы

    // Блокируем кнопку отправки и очищаем предыдущие сообщения
    var submitBtn = $('#withdrawal-submit');
    var withdrawalAmount = $('#withdrawal-amount');

    // Проверяем, заблокирована ли форма (чтобы избежать двойного нажатия)
    if (submitBtn.prop('disabled')) {
      return false;
    }

    // Получаем введенную сумму
    var amount = parseFloat(withdrawalAmount.val());

    // Проверяем, введена ли сумма
    if (isNaN(amount) || amount <= 0) {
      $('#withdrawal-messages').html(
        '<div class="error-message">' +
          'Пожалуйста, введите корректную сумму для вывода.' +
          '</div>',
      );
      return false;
    }

    // Блокируем форму во время обработки
    submitBtn.prop('disabled', true);
    withdrawalAmount.prop('disabled', true);
    submitBtn.val('Обработка...'); // Меняем текст кнопки

    // Подготовка данных для AJAX запроса
    var data = {
      action: 'process_cashback_withdrawal',
      withdrawal_amount: amount,
      nonce: cashback_ajax.nonce,
    };

    // Отправляем AJAX запрос
    $.ajax({
      url: cashback_ajax.ajax_url,
      type: 'POST',
      data: data,
      success: function (response) {
        if (response.success) {
          // Успешный вывод
          $('#withdrawal-messages').html(
            '<div class="success-message">' + response.data + '</div>',
          );

          // Очищаем поле ввода
          withdrawalAmount.val('');

          // Обновляем баланс пользователя через AJAX
          updateBalanceDisplay();
        } else {
          // Ошибка
          $('#withdrawal-messages').html('<div class="error-message">' + response.data + '</div>');
        }
      },
      error: function () {
        // Ошибка соединения
        $('#withdrawal-messages').html(
          '<div class="error-message">' +
            'Ошибка соединения. Пожалуйста, попробуйте еще раз.' +
            '</div>',
        );
      },
      complete: function () {
        // Разблокируем форму после завершения запроса
        submitBtn.prop('disabled', false);
        withdrawalAmount.prop('disabled', false);
        submitBtn.val('Вывести'); // Возвращаем первоначальный текст кнопки
      },
    });
  });
});

/**
 * Функция для обновления отображения баланса пользователя
 */
function updateBalanceDisplay() {
  jQuery.ajax({
    url: cashback_ajax.ajax_url,
    type: 'POST',
    data: {
      action: 'get_user_balance',
      nonce: cashback_ajax.nonce,
    },
    success: function (response) {
      if (response.success) {
        // Обновляем отображение баланса на странице
        jQuery('#cashback-balance-amount').html(response.data.formatted_balance);

        // Обновляем максимальное значение для поля ввода
        jQuery('#withdrawal-amount').attr('max', response.data.balance);
      }
    },
    error: function () {
      console.log('Ошибка при обновлении баланса');
    },
  });
}
