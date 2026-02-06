/**
 * Cashback History Pagination Handler
 * @package CashbackHistory
 */

(function ($) {
  'use strict';

  /**
   * Initialize pagination click handler for cashback history page.
   * Uses delegated event binding on #pagination-container.
   */
  const initPagination = function () {
    if (typeof cashback_history_ajax === 'undefined') {
      return;
    }

    // Only initialize on cashback history page
    if (cashback_history_ajax.is_cashback_page !== 'true') {
      return;
    }

    $(document).on('click', '#pagination-container .page-numbers', async function (e) {
      e.preventDefault();
      const $this = $(this);
      const page = $this.data('page');

      if (!page) {
        return;
      }

      const $container = $('#pagination-container');

      // Update active state
      $container.find('.page-numbers').removeClass('current');
      $this.addClass('current');

      try {
        const response = await $.ajax({
          url: cashback_history_ajax.ajax_url,
          type: 'POST',
          data: {
            action: 'load_page_transactions',
            nonce: cashback_history_ajax.nonce,
            page: page,
          },
        });

        if (response.success) {
          $('#transactions-body').html(response.data.html);
        } else {
          console.error('Cashback History: Error loading data:', response.data);
        }
      } catch (error) {
        console.error('Cashback History AJAX Error:', error);
      }
    });
  };

  // Initialize on document ready
  $(document).ready(function () {
    initPagination();
  });
})(jQuery);
