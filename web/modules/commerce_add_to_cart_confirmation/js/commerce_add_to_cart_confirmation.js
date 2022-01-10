(function ($, Drupal) {

  'use strict';

  Drupal.behaviors.commerce_add_to_cart_confirmation = {
    attach:function (context) {
      $('.commerce-add-to-cart-confirmation', context).once('commerce-add-to-cart-confirmation').each(function() {
        var original_popup_content = $(this);
        if (original_popup_content.length > 0) {
          var popup_content = original_popup_content.clone();
          original_popup_content.remove();
          var popup_title = popup_content.find('.added-product-title').html();
          popup_content.find('.added-product-title').remove();
          var confirmationModal = Drupal.dialog(popup_content, {
            title: popup_title,
            dialogClass: 'commerce-confirmation-popup',
            width: 745,
            height: 375,
            maxWidth: '95%',
            autoResize: true,
            resizable: false,
            close: function (event) {
              $(event.target).remove();
            },
          });
          confirmationModal.showModal();

          popup_content.on('click touchend', '.commerce-add-to-cart-confirmation-close', function() {
            Drupal.dialog(popup_content).close();
          });
        }
      });
    }
  }
})(jQuery, Drupal);
