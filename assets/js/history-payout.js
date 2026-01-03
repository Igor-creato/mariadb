jQuery(document).ready(function ($) {
  $(document).on('click', '.page-numbers', function (e) {
    e.preventDefault();
    var page = $(this).data('page');
    if (!page) return;

    var container = $('.woocommerce-pagination');

    container.find('.page-numbers').removeClass('current');
    $(this).addClass('current');

    $.ajax({
      url: payout_ajax.ajax_url,
      type: 'POST',
      data: {
        action: 'load_page_payouts',
        nonce: payout_ajax.nonce,
        page: page,
      },
      success: function (response) {
        if (response.success) {
          $('#payouts-body').html(response.data.html);
        } else {
          alert('Ошибка загрузки данных.');
        }
      },
      error: function () {
        alert('Ошибка AJAX.');
      },
    });
  });
});
