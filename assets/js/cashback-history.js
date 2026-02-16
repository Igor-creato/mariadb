/**
 * Cashback History Pagination Handler
 * @package CashbackHistory
 */

(function ($) {
  'use strict';

  /**
   * Build pagination HTML based on current page and total pages.
   *
   * @param {number} currentPage Current active page.
   * @param {number} totalPages  Total number of pages.
   * @return {string} Pagination HTML string.
   */
  function buildPagination(currentPage, totalPages) {
    if (totalPages <= 1) {
      return '';
    }

    var range = 2;
    var edge = 2;
    var pagesSet = {};
    var i;

    // Edge pages from the start
    for (i = 1; i <= Math.min(edge, totalPages); i++) {
      pagesSet[i] = true;
    }
    // Pages around current
    for (i = Math.max(1, currentPage - range); i <= Math.min(totalPages, currentPage + range); i++) {
      pagesSet[i] = true;
    }
    // Edge pages from the end
    for (i = Math.max(1, totalPages - edge + 1); i <= totalPages; i++) {
      pagesSet[i] = true;
    }

    var pages = Object.keys(pagesSet).map(Number).sort(function (a, b) { return a - b; });

    var html = '<nav class="woocommerce-pagination"><ul class="page-numbers">';

    // Back arrow — only if not on first page
    if (currentPage > 1) {
      html += '<li><a href="#" class="page-numbers prev" data-page="' + (currentPage - 1) + '">&lsaquo;</a></li>';
    }

    var prev = 0;
    for (i = 0; i < pages.length; i++) {
      var page = pages[i];
      if (prev && page - prev > 1) {
        html += '<li><span class="page-numbers dots">&hellip;</span></li>';
      }
      var cls = (page === currentPage) ? 'current' : '';
      html += '<li><a href="#" class="page-numbers ' + cls + '" data-page="' + page + '">' + page + '</a></li>';
      prev = page;
    }

    // Forward arrow — only if not on last page
    if (currentPage < totalPages) {
      html += '<li><a href="#" class="page-numbers next" data-page="' + (currentPage + 1) + '">&rsaquo;</a></li>';
    }

    html += '</ul></nav>';
    return html;
  }

  /**
   * Initialize pagination click handler for cashback history page.
   * Uses delegated event binding on #pagination-container.
   */
  var initPagination = function () {
    if (typeof cashback_history_ajax === 'undefined') {
      return;
    }

    // Only initialize on cashback history page
    if (cashback_history_ajax.is_cashback_page !== 'true') {
      return;
    }

    $(document).on('click', '#pagination-container .page-numbers[data-page]', function (e) {
      e.preventDefault();
      var $this = $(this);
      var page = $this.data('page');

      if (!page) {
        return;
      }

      $.ajax({
        url: cashback_history_ajax.ajax_url,
        type: 'POST',
        data: {
          action: 'load_page_transactions',
          nonce: cashback_history_ajax.nonce,
          page: page,
        },
        success: function (response) {
          if (response.success) {
            $('#transactions-body').html(response.data.html);
            $('#pagination-container').html(
              buildPagination(response.data.current_page, response.data.total_pages)
            );
          } else {
            console.error('Cashback History: Error loading data:', response.data);
          }
        },
        error: function (error) {
          console.error('Cashback History AJAX Error:', error);
        },
      });
    });
  };

  // Initialize on document ready
  $(document).ready(function () {
    initPagination();
  });
})(jQuery);
