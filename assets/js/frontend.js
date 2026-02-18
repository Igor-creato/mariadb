/**
 * WC Affiliate URL Params — Guest Warning Modal
 *
 * Для авторизованных пользователей: JS-перехват не нужен.
 * Ссылки ведут на ?cashback_click={id}, сервер делает 302 redirect.
 *
 * Для гостей: перехватываем клик, показываем модалку с предупреждением.
 * Кнопка «Продолжить» — обычная ссылка на redirect endpoint.
 *
 * @since 4.0.0
 */
(function ($) {
  'use strict';

  // Авторизованные пользователи — никакого JS-перехвата,
  // ссылки работают как обычные <a href target=_blank>
  if (wcAffiliateParams.isLoggedIn) {
    return;
  }

  $(document).ready(function () {
    // Перехват кликов — ТОЛЬКО для гостей
    $(document).on(
      'click',
      'a.add_to_cart_button, a.single_add_to_cart_button, a.product_type_external, a.add-to-cart-loop',
      function (e) {
        var $button = $(this);
        var productId = $button.data('product-id');
        var href = $button.attr('href') || '';

        // Только партнерские товары (href содержит cashback_click и есть data-product-id)
        if (!productId || href.indexOf('cashback_click=') === -1) {
          return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        showAuthWarning($button);
        return false;
      },
    );
  });

  /**
   * Показ модального окна для неавторизованных пользователей
   *
   * @param {jQuery} $button Кнопка, по которой кликнули
   */
  function showAuthWarning($button) {
    // Удаляем существующее модальное окно
    $('#wc-affiliate-warning-modal').remove();

    var redirectUrl = $button.attr('href');

    var modal =
      '<div id="wc-affiliate-warning-modal" class="wc-affiliate-modal">' +
      '<div class="wc-affiliate-modal-content">' +
      '<span class="wc-affiliate-modal-close">&times;</span>' +
      '<div class="wc-affiliate-modal-icon">\u26A0\uFE0F</div>' +
      '<h3 class="wc-affiliate-modal-title">\u0412\u043D\u0438\u043C\u0430\u043D\u0438\u0435</h3>' +
      '<p class="wc-affiliate-modal-message">' +
      wcAffiliateParams.warningMessage +
      '</p>' +
      '<div class="wc-affiliate-modal-actions">' +
      '<a href="' +
      redirectUrl +
      '" target="_blank" rel="nofollow" class="wc-affiliate-btn wc-affiliate-btn-primary" id="wc-affiliate-continue">' +
      '\u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u044C \u0431\u0435\u0437 \u0430\u0432\u0442\u043E\u0440\u0438\u0437\u0430\u0446\u0438\u0438</a>' +
      '<a href="' +
      wcAffiliateParams.loginUrl +
      '" class="wc-affiliate-btn wc-affiliate-btn-secondary" id="wc-affiliate-cancel">' +
      '\u0410\u0432\u0442\u043E\u0440\u0438\u0437\u043E\u0432\u0430\u0442\u044C\u0441\u044F \u0438\u043B\u0438 \u0437\u0430\u0440\u0435\u0433\u0438\u0441\u0442\u0440\u0438\u0440\u043E\u0432\u0430\u0442\u044C\u0441\u044F</a>' +
      '</div></div></div>';

    $('body').append(modal);

    setTimeout(function () {
      $('#wc-affiliate-warning-modal').addClass('show');
    }, 10);

    // «Продолжить без авторизации» — обычная ссылка, закрываем модалку
    $('#wc-affiliate-continue').on('click', function () {
      closeModal();
      // Ссылка — обычный <a href target=_blank>, браузер сам откроет
    });

    // Закрытие по крестику
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
    setTimeout(function () {
      $('#wc-affiliate-warning-modal').remove();
      $(window).off('click.wcAffiliateModal');
    }, 300);
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

  /**
   * Валидация номера телефона для СБП.
   * Должен начинаться с +, далее 10-15 цифр (E.164).
   * @param {string} phone
   * @returns {string} Сообщение об ошибке или пустая строка
   */
  function validatePhoneNumber(phone) {
    var cleaned = phone.replace(/[\s\-\(\)]/g, '');

    if (!cleaned || cleaned.charAt(0) !== '+') {
      return 'Номер телефона должен начинаться с кода страны (например, +79001234567).';
    }

    var digits = cleaned.substring(1);

    if (!/^\d+$/.test(digits)) {
      return 'Номер телефона содержит недопустимые символы.';
    }

    if (digits.length < 10 || digits.length > 15) {
      return 'Введите полный номер телефона с кодом страны (10-15 цифр после +).';
    }

    return '';
  }

  /**
   * Валидация номера карты МИР.
   * 16 цифр, BIN 2200-2204, проверка Luhn.
   * @param {string} card
   * @returns {string} Сообщение об ошибке или пустая строка
   */
  function validateMirCard(card) {
    var cleaned = card.replace(/[\s\-]/g, '');

    if (!/^\d+$/.test(cleaned)) {
      return 'Номер карты должен содержать только цифры.';
    }

    if (cleaned.length !== 16) {
      return 'Номер карты МИР должен содержать 16 цифр.';
    }

    var binPrefix = parseInt(cleaned.substring(0, 4), 10);
    if (binPrefix < 2200 || binPrefix > 2204) {
      return 'Номер карты МИР должен начинаться с 2200-2204.';
    }

    if (!luhnCheck(cleaned)) {
      return 'Некорректный номер карты (не прошёл проверку контрольной суммы).';
    }

    return '';
  }

  /**
   * Алгоритм Луна для проверки номера карты.
   * @param {string} number Строка только из цифр
   * @returns {boolean}
   */
  function luhnCheck(number) {
    var sum = 0;
    var parity = number.length % 2;

    for (var i = 0; i < number.length; i++) {
      var digit = parseInt(number.charAt(i), 10);

      if (i % 2 === parity) {
        digit *= 2;
        if (digit > 9) {
          digit -= 9;
        }
      }

      sum += digit;
    }

    return sum % 10 === 0;
  }

  // Сброс подсветки ошибок и обновление label/placeholder при смене способа вывода
  $(document).on('change', '#payout_method_id', function () {
    $(this).removeClass('input--error');

    var selectedOption = $(this).find('option:selected');
    var methodSlug = selectedOption.data('slug') || '';
    var $label = $('label[for="payout_account"]');
    var $input = $('#payout_account');

    switch (methodSlug) {
      case 'sbp':
        $label.html('Номер телефона <span class="required">*</span>');
        $input.attr('placeholder', '+79001234567');
        $input.attr('type', 'tel');
        $input.attr('inputmode', 'tel');
        $input.attr('maxlength', '20');
        break;
      case 'mir':
        $label.html('Номер карты МИР <span class="required">*</span>');
        $input.attr('placeholder', '2200 XXXX XXXX XXXX');
        $input.attr('type', 'text');
        $input.attr('inputmode', 'numeric');
        $input.attr('maxlength', '19');
        break;
      default:
        $label.html('Номер счета или телефона <span class="required">*</span>');
        $input.attr('placeholder', '');
        $input.attr('type', 'text');
        $input.removeAttr('inputmode');
        $input.attr('maxlength', '50');
        break;
    }

    $input.removeClass('input--error');
    $('#payout_settings_message').text('').removeClass('success error');
  });

  // Сброс ошибки и авто-форматирование при вводе номера счёта/карты
  $(document).on('input', '#payout_account', function () {
    $(this).removeClass('input--error');

    var selectedOption = $('#payout_method_id option:selected');
    var methodSlug = selectedOption.data('slug') || '';

    if (methodSlug === 'mir') {
      var cursorPos = this.selectionStart;
      var rawValue = $(this).val().replace(/\D/g, '');
      rawValue = rawValue.substring(0, 16);

      var formatted = rawValue.replace(/(\d{4})(?=\d)/g, '$1 ');

      var oldVal = $(this).val();
      $(this).val(formatted);

      var spacesBeforeOld = (oldVal.substring(0, cursorPos).match(/ /g) || []).length;
      var spacesBeforeNew = (formatted.substring(0, cursorPos + (formatted.length - oldVal.length)).match(/ /g) || []).length;
      var newPos = cursorPos + (spacesBeforeNew - spacesBeforeOld);
      this.setSelectionRange(newPos, newPos);
    }
  });

  // При загрузке страницы — обновить label/placeholder для уже выбранного метода
  if ($('#payout_method_id').val()) {
    $('#payout_method_id').trigger('change');
  }

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

    // Валидация формата номера счёта в зависимости от выбранного способа вывода
    var selectedOption = $('#payout_method_id option:selected');
    var methodSlug = selectedOption.data('slug') || '';

    if (methodSlug === 'sbp') {
      var phoneError = validatePhoneNumber(payoutAccount);
      if (phoneError) {
        $('#payout_account').addClass('input--error');
        $('#payout_settings_message')
          .removeClass('success')
          .addClass('error')
          .text(phoneError);
        return;
      }
    } else if (methodSlug === 'mir') {
      var cardError = validateMirCard(payoutAccount);
      if (cardError) {
        $('#payout_account').addClass('input--error');
        $('#payout_settings_message')
          .removeClass('success')
          .addClass('error')
          .text(cardError);
        return;
      }
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
