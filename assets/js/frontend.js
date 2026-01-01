(function ($) {
  'use strict';

  $(document).ready(function () {
    console.log('WC Affiliate URL Params: Script loaded');
    console.log('WC Affiliate Params:', wcAffiliateParams);

    // Единый обработчик для всех кликов - проверяем каждый элемент
    $(document).on('click', function (e) {
      const $element = $(e.target);
      const $link = $element.closest('a');
      const $button = $element.closest('button, input[type="submit"], input[type="button"]');

      console.log(
        'WC Affiliate: Click detected on element:',
        $element,
        'Link:',
        $link.length > 0,
        'Button:',
        $button.length > 0,
      );

      // Определяем целевой элемент (ссылка или кнопка)
      let $target = null;
      let href = null;

      if ($link.length > 0) {
        $target = $link;
        href = $link.attr('href');
      } else if ($button.length > 0) {
        $target = $button;
        href = $button.attr('href') || $button.data('href');

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
              .closest('.product, .wd-quick-view, .quick-view-modal, .wd-popup, .single-product')
              .find('a[href*="USER_PLACEHOLDER_"]')
              .attr('href');
          }

          // Последний вариант - глобальный поиск
          if (!href) {
            href = $('a[href*="USER_PLACEHOLDER_"]').first().attr('href');
          }
        }
      }

      if (!$target || !href) {
        console.log('WC Affiliate: No target or href found, skipping');
        return; // Не наш элемент
      }

      console.log('WC Affiliate: Found target element:', $target, 'with href:', href);

      // ПРОВЕРКА: является ли элемент элементом управления модальным окном
      // НО НЕ фильтруем кнопки покупки, даже если они внутри модальных окон
      const isPurchaseButton =
        $target.hasClass('single_add_to_cart_button') ||
        $target.hasClass('add_to_cart_button') ||
        ($target.hasClass('button') && $target.closest('form').length > 0) ||
        $target.is('button[name="add-to-cart"]') ||
        $target.is('input[type="submit"]');

      if (
        !isPurchaseButton && // Не фильтруем кнопки покупки
        ($target.closest(
          '.wd-popup, .wd-modal, .wd-quick-view, .quick-view-modal, .mfp-wrap, .modal, .popup, .overlay',
        ).length > 0 ||
          $target.hasClass('wd-popup-close') ||
          $target.hasClass('wd-modal-close') ||
          $target.hasClass('mfp-close') ||
          $target.closest('.wd-popup-close, .wd-modal-close, .mfp-close').length > 0 ||
          $target.is('[data-mfp-close]') ||
          $target.closest('[data-mfp-close]').length > 0 ||
          $target.is('.modal-close, .close-modal, .popup-close, .close, .x, .cross') ||
          $target.closest('.modal-close, .close-modal, .popup-close, .close, .x, .cross').length >
            0 ||
          $target.text().trim() === '×' ||
          $target.text().trim() === '✕' ||
          $target.text().trim() === '✖' ||
          $target.text().trim() === '×' ||
          $target.attr('aria-label') === 'Close' ||
          $target.attr('title') === 'Close' ||
          $target.attr('data-dismiss') === 'modal' ||
          $target.attr('data-close') === 'true')
      ) {
        console.log('WC Affiliate: Skipping modal control element:', $target);
        return; // Не перехватываем элементы управления модальными окнами
      }

      // ПРОВЕРКА: содержит ли href USER_PLACEHOLDER_
      if (!href || !href.includes('USER_PLACEHOLDER_')) {
        console.log('WC Affiliate: Href does not contain USER_PLACEHOLDER_, skipping');
        return; // Не наш элемент
      }

      console.log('WC Affiliate: Intercepted element with USER_PLACEHOLDER_:', href, $target);

      // Проверяем авторизацию
      if (!wcAffiliateParams.isLoggedIn) {
        console.log('WC Affiliate: User not logged in, showing warning');
        e.preventDefault();
        e.stopImmediatePropagation();
        showAuthWarning($target, href);
        return false;
      } else {
        console.log('WC Affiliate: User logged in, replacing placeholder');
        // Если пользователь авторизован, заменяем плейсхолдер на ID
        const finalUrl = replaceUserPlaceholders(href, wcAffiliateParams.userId);
        $target.attr('href', finalUrl);
        // Продолжаем стандартное поведение
      }
    });
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
