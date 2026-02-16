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
  // Переключение вкладок
  $(document).on('click', '.cashback-tab', function () {
    $('.cashback-tab').removeClass('active cashback-tab--error');
    $(this).addClass('active');
    var target = $(this).data('tab');
    $('.cashback-tab-content').removeClass('active');
    $('#' + target).addClass('active');
  });

  // Сброс подсветки ошибки при вводе суммы
  $('#withdrawal-amount').on('input', function () {
    $(this).removeClass('input--error');
  });

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
    var amountInput = withdrawalAmount.val();
    // Заменяем запятую на точку для корректной обработки чисел
    var amount = parseFloat(amountInput.replace(',', '.'));

    // Проверяем, является ли введенное значение корректным числом
    if (isNaN(amount) || amount <= 0) {
      withdrawalAmount.addClass('input--error');
      $('#withdrawal-messages').html(
        '<div class="error-message">' +
          'Пожалуйста, введите корректную сумму для вывода.' +
          '</div>',
      );
      // Разблокируем форму, так как не отправляем запрос
      submitBtn.prop('disabled', false);
      withdrawalAmount.prop('disabled', false);
      submitBtn.val('Вывести'); // Возвращаем первоначальный текст кнопки
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
          // Ошибка — поддержка как строки, так и объекта с message и show_form
          var errorMsg =
            typeof response.data === 'object' && response.data !== null
              ? response.data.message
              : response.data;
          $('#withdrawal-messages').html('<div class="error-message">' + errorMsg + '</div>');

          // Если сервер указал показать форму настроек (платёжная система или банк неактивны)
          var isFormError =
            typeof response.data === 'object' &&
            response.data !== null &&
            response.data.show_form;

          if (isFormError) {
            // Подсвечиваем вкладку настроек красным
            $('.cashback-tab[data-tab="tab-settings"]').addClass('cashback-tab--error');
            // Переключаемся на вкладку настроек
            $('.cashback-tab').removeClass('active');
            $('.cashback-tab[data-tab="tab-settings"]').addClass('active');
            $('.cashback-tab-content').removeClass('active');
            $('#tab-settings').addClass('active');
            // Показываем форму редактирования
            $('#payout_settings_display').hide();
            $('#payout_settings_form').removeClass('payout-settings-form-hidden').show();
          } else {
            // Ошибка связана с суммой — подсвечиваем поле
            withdrawalAmount.addClass('input--error');
          }
        }
      },
      error: function (xhr, status, error) {
        // Ошибка соединения или серверная ошибка
        var errorMessage = 'Ошибка соединения. Пожалуйста, попробуйте еще раз.';
        if (xhr.status === 403) {
          errorMessage = 'Ошибка авторизации. Пожалуйста, войдите в систему и попробуйте снова.';
        } else if (xhr.status === 400) {
          errorMessage = 'Неверный запрос. Пожалуйста, обновите страницу и попробуйте снова.';
        } else if (xhr.status === 500) {
          errorMessage = 'Внутренняя ошибка сервера. Пожалуйста, попробуйте позже.';
        }
        $('#withdrawal-messages').html('<div class="error-message">' + errorMessage + '</div>');
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
        // Обновляем отображение всех трёх балансов
        jQuery('#cashback-balance-amount').html(response.data.formatted_balance);
        jQuery('#cashback-pending-amount').html(response.data.formatted_pending);
        jQuery('#cashback-paid-amount').html(response.data.formatted_paid);

        // Обновляем максимальное значение для поля ввода
        jQuery('#withdrawal-amount').attr('max', response.data.balance);
      }
    },
    error: function () {
      console.log('Ошибка при обновлении баланса');
    },
  });
}

/**
 * Обработчик для кнопки "Сохранить настройки" на странице вывода кэшбэка
 */
jQuery(document).ready(function ($) {
  // Проверяем наличие cashback_ajax
  if (typeof cashback_ajax === 'undefined') {
    console.error('cashback_ajax is not defined');
    return;
  }

  console.log('Payout settings handler loaded', cashback_ajax);

  // Сброс подсветки ошибок при взаимодействии с полями
  $(document).on('change', '#payout_method_id', function () {
    $(this).removeClass('input--error');
  });

  $(document).on('input', '#payout_account', function () {
    $(this).removeClass('input--error');
  });

  // ============================================================
  // Компонент поиска банков с AJAX, клавиатурной навигацией и a11y
  // ============================================================
  var bankSearchTimer = null;
  // Если bank_id уже установлен (сохранённые настройки), считаем что банк выбран
  var bankSelectedFromList =
    $('#bank_id').val() && parseInt($('#bank_id').val(), 10) > 0 ? true : false;
  var bankActiveIndex = -1; // Текущий индекс для клавиатурной навигации

  /**
   * Экранирование HTML-символов для защиты от XSS при вставке в DOM
   * @param {string} str
   * @returns {string}
   */
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  /**
   * Показать выпадающий список банков
   */
  function showBankDropdown() {
    var $results = $('#bank_search_results');
    var $wrapper = $('.bank-search-wrapper');
    $results.addClass('bank-search-results--visible');
    $wrapper.attr('aria-expanded', 'true');
  }

  /**
   * Скрыть выпадающий список банков
   */
  function hideBankDropdown() {
    var $results = $('#bank_search_results');
    var $wrapper = $('.bank-search-wrapper');
    $results.removeClass('bank-search-results--visible');
    $wrapper.attr('aria-expanded', 'false');
    bankActiveIndex = -1;
    // Снимаем активный выделение
    $results.find('.bank-search-item').removeClass('bank-search-item--active');
  }

  /**
   * Обновить список банков в DOM
   * @param {Array} banks - Массив банков [{id, name}]
   */
  function updateBankList(banks) {
    var $results = $('#bank_search_results');
    $results.empty();
    bankActiveIndex = -1;

    if (banks.length === 0) {
      $results.append(
        '<li class="bank-search-no-results" role="option" aria-disabled="true">Банк не найден</li>',
      );
    } else {
      var selectedBankId = $('#bank_id').val();
      $.each(banks, function (idx, bank) {
        var isSelected = String(bank.id) === String(selectedBankId);
        $results.append(
          '<li class="bank-search-item" role="option" aria-selected="' +
            (isSelected ? 'true' : 'false') +
            '" data-bank-id="' +
            escapeHtml(String(bank.id)) +
            '" data-bank-name="' +
            escapeHtml(bank.name) +
            '" tabindex="-1">' +
            escapeHtml(bank.name) +
            '</li>',
        );
      });
    }
    showBankDropdown();
  }

  /**
   * AJAX поиск банков
   * @param {string} searchTerm
   */
  function searchBanks(searchTerm) {
    $.ajax({
      url: cashback_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'search_banks',
        search: searchTerm,
        security: cashback_ajax.nonce,
      },
      success: function (response) {
        if (response.success && response.data && response.data.banks) {
          updateBankList(response.data.banks);
        } else {
          updateBankList([]);
        }
      },
      error: function () {
        updateBankList([]);
      },
    });
  }

  /**
   * Выбрать банк из списка
   * @param {jQuery} $item
   */
  function selectBank($item) {
    var bankId = $item.data('bank-id');
    var bankName = $item.data('bank-name');

    $('#bank_id').val(bankId);
    $('#bank_search_input').val(bankName);
    bankSelectedFromList = true;

    // Убрать ошибку, если была
    $('#bank_search_error').text('').hide();
    $('#bank_search_input').removeClass('bank-search-input--error');

    // Обновить aria-selected
    $('#bank_search_results .bank-search-item').attr('aria-selected', 'false');
    $item.attr('aria-selected', 'true');

    hideBankDropdown();
  }

  /**
   * Клавиатурная навигация по списку банков
   * @param {string} direction - 'up' или 'down'
   */
  function navigateBankList(direction) {
    var $items = $('#bank_search_results .bank-search-item');
    if ($items.length === 0) return;

    $items.removeClass('bank-search-item--active');

    if (direction === 'down') {
      bankActiveIndex = bankActiveIndex < $items.length - 1 ? bankActiveIndex + 1 : 0;
    } else if (direction === 'up') {
      bankActiveIndex = bankActiveIndex > 0 ? bankActiveIndex - 1 : $items.length - 1;
    }

    var $active = $items.eq(bankActiveIndex);
    $active.addClass('bank-search-item--active');
    // Прокрутка к активному элементу
    $active[0].scrollIntoView({ block: 'nearest' });
    // Обновляем aria-activedescendant
    var itemId = 'bank-item-' + bankActiveIndex;
    $active.attr('id', itemId);
    $('#bank_search_input').attr('aria-activedescendant', itemId);
  }

  // --- Обработчики событий для поиска банков ---

  // При фокусе на поле ввода — показать начальный список
  $(document).on('focus', '#bank_search_input', function () {
    var $results = $('#bank_search_results');
    if ($results.find('.bank-search-item').length > 0) {
      showBankDropdown();
    } else {
      // Если список пуст, загружаем первые 10
      searchBanks('');
    }
  });

  // При вводе текста — поиск с debounce
  $(document).on('input', '#bank_search_input', function () {
    var searchTerm = $(this).val().trim();

    // Сбрасываем выбор, так как пользователь редактирует
    bankSelectedFromList = false;
    $('#bank_id').val('');

    clearTimeout(bankSearchTimer);
    bankSearchTimer = setTimeout(function () {
      searchBanks(searchTerm);
    }, 300); // Debounce 300ms
  });

  // Клавиатурная навигация
  $(document).on('keydown', '#bank_search_input', function (e) {
    var $results = $('#bank_search_results');
    var isVisible = $results.hasClass('bank-search-results--visible');

    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        if (!isVisible) {
          showBankDropdown();
        }
        navigateBankList('down');
        break;
      case 'ArrowUp':
        e.preventDefault();
        if (isVisible) {
          navigateBankList('up');
        }
        break;
      case 'Enter':
        e.preventDefault();
        if (isVisible && bankActiveIndex >= 0) {
          var $active = $results.find('.bank-search-item').eq(bankActiveIndex);
          if ($active.length) {
            selectBank($active);
          }
        }
        break;
      case 'Escape':
        e.preventDefault();
        hideBankDropdown();
        break;
      case 'Tab':
        hideBankDropdown();
        break;
    }
  });

  // Клик по элементу списка
  $(document).on('click', '#bank_search_results .bank-search-item', function (e) {
    e.preventDefault();
    e.stopPropagation();
    selectBank($(this));
  });

  // Закрытие списка при клике вне компонента
  $(document).on('click', function (e) {
    if (
      !$(e.target).closest('.bank-search-wrapper').length &&
      !$(e.target).is('#bank_search_input')
    ) {
      hideBankDropdown();
    }
  });

  // Hover-эффект для элементов списка
  $(document).on('mouseenter', '#bank_search_results .bank-search-item', function () {
    $('#bank_search_results .bank-search-item').removeClass('bank-search-item--active');
    $(this).addClass('bank-search-item--active');
    bankActiveIndex = $(this).index();
  });

  // ============================================================
  // Конец компонента поиска банков
  // ============================================================

  /**
   * Обработчик кнопки "Изменить данные"
   */
  $(document).on('click', '#edit_payout_settings_btn', function (e) {
    e.preventDefault();
    console.log('Edit payout settings button clicked');

    // Скрываем блок с отображением данных
    $('#payout_settings_display').hide();
    // Показываем форму редактирования
    $('#payout_settings_form').removeClass('payout-settings-form-hidden').show();
    // Очищаем сообщения
    $('#payout_settings_message').text('').removeClass('success error');
  });

  /**
   * Обработчик кнопки "Отменить" при редактировании
   */
  $(document).on('click', '#cancel_edit_payout_settings_btn', function (e) {
    e.preventDefault();
    console.log('Cancel edit payout settings button clicked');

    // Показываем блок с отображением данных
    $('#payout_settings_display').show();
    // Скрываем форму редактирования
    $('#payout_settings_form').addClass('payout-settings-form-hidden').hide();
    // Очищаем сообщения
    $('#payout_settings_message').text('').removeClass('success error');
  });

  /**
   * Обработчик кнопки "Сохранить настройки"
   */
  $(document).on('click', '#save_payout_settings_btn', function (e) {
    e.preventDefault();
    console.log('Save payout settings button clicked');

    const payoutMethodId = $('#payout_method_id').val();
    const payoutAccount = $('#payout_account').val();
    const bankId = $('#bank_id').val();
    const bankInputVal = $('#bank_search_input').val().trim();
    const nonce = cashback_ajax.nonce;

    console.log('Form values:', { payoutMethodId, payoutAccount, bankId, bankInputVal });

    // Сбрасываем подсветку ошибок перед валидацией
    $('#payout_method_id').removeClass('input--error');
    $('#payout_account').removeClass('input--error');
    $('#bank_search_input').removeClass('bank-search-input--error');
    $('#bank_search_error').text('').hide();

    // Валидация
    var hasErrors = false;

    if (!payoutMethodId || payoutMethodId === '' || payoutMethodId === '0') {
      $('#payout_method_id').addClass('input--error');
      $('#payout_settings_message')
        .removeClass('success')
        .addClass('error')
        .text('Пожалуйста, выберите способ вывода');
      hasErrors = true;
    }

    if (!payoutAccount || !payoutAccount.trim()) {
      $('#payout_account').addClass('input--error');
      if (!hasErrors) {
        $('#payout_settings_message')
          .removeClass('success')
          .addClass('error')
          .text('Пожалуйста, введите номер счета или телефона');
      }
      hasErrors = true;
    }

    // Валидация банка: проверяем что пользователь выбрал из списка
    if (!bankId || bankId === '' || bankId === '0' || parseInt(bankId, 10) <= 0) {
      $('#bank_search_input').addClass('bank-search-input--error');
      // Если введено название но не выбрано из списка
      if (bankInputVal.length > 0 && !bankSelectedFromList) {
        $('#bank_search_error').text('Вы не выбрали банк из списка').show();
        if (!hasErrors) {
          $('#payout_settings_message')
            .removeClass('success')
            .addClass('error')
            .text('Вы не выбрали банк из списка');
        }
      } else {
        if (!hasErrors) {
          $('#payout_settings_message')
            .removeClass('success')
            .addClass('error')
            .text('Пожалуйста, выберите банк');
        }
      }
      hasErrors = true;
    } else if (bankInputVal.length > 0 && !bankSelectedFromList) {
      // Дополнительная проверка: если bank_id есть, но bankSelectedFromList = false
      // и текст в поле отличается от выбранного — значит пользователь изменил текст после выбора
      $('#bank_search_error').text('Вы не выбрали банк из списка').show();
      $('#bank_search_input').addClass('bank-search-input--error');
      if (!hasErrors) {
        $('#payout_settings_message')
          .removeClass('success')
          .addClass('error')
          .text('Вы не выбрали банк из списка');
      }
      hasErrors = true;
    }

    if (hasErrors) {
      return;
    }

    // Очищаем предыдущие сообщения
    $('#payout_settings_message').text('').removeClass('success error');

    console.log('Sending AJAX request to:', cashback_ajax.ajax_url);

    // Отправляем AJAX-запрос
    $.ajax({
      url: cashback_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'save_payout_settings',
        payout_method_id: payoutMethodId,
        payout_account: payoutAccount,
        bank_id: bankId,
        security: nonce,
      },
      beforeSend: function () {
        console.log('AJAX request started');
        $('#save_payout_settings_btn').prop('disabled', true).text('Сохранение...');
      },
      success: function (response) {
        console.log('AJAX response:', response);
        if (response.success) {
          $('#payout_settings_message')
            .removeClass('error')
            .addClass('success')
            .text(response.data.message);

          // Перезагружаем страницу через 1.5 секунды для отображения обновленных данных
          setTimeout(function () {
            location.reload();
          }, 1500);
        } else {
          $('#payout_settings_message')
            .removeClass('success')
            .addClass('error')
            .text(response.data.message || 'Ошибка при сохранении данных');
        }
      },
      error: function (xhr, status, error) {
        console.error('AJAX error:', xhr, status, error);
        $('#payout_settings_message')
          .removeClass('success')
          .addClass('error')
          .text('Ошибка соединения');
      },
      complete: function () {
        console.log('AJAX request completed');
        $('#save_payout_settings_btn').prop('disabled', false).text('Сохранить настройки');
      },
    });
  });
});
