(function ($, Drupal, settings) {
  'use strict';

  Drupal.paypalCheckout = {
    cardFieldsSelector: '',
    onCreateUrl: '',
    makeCall: function(url, params) {
      params = params || {};
      var ajaxSettings = {
        dataType: 'json',
        url: url
      };
      $.extend(ajaxSettings, params);
      return $.ajax(ajaxSettings);
    },
    renderForm: function(context) {
      var $cardFields = $(this.cardFieldsSelector, context).once('paypal');
      // If the card fields form isn't present in the page, stop here.
      if ($cardFields.length === 0) {
        return;
      }
      var $messagesContainer = $cardFields.find('.paypal-messages');
      // If the current site is not eligible, display an error message.
      if (paypal.HostedFields.isEligible() !== true) {
        var message = Drupal.t('PayPal has indicated that the current site is not eligible to show the credit card form. Please review your configuration and try again.');
        $messagesContainer.html(Drupal.theme('commercePaypalError', message));
        return;
      }
      $cardFields.find('.commerce-paypal-card-fields-wrapper').show();
      var $form = $cardFields.closest('form');
      var $submit = $form.find('.button--primary');
      var $data = Drupal.paypalCheckout.extractBillingInfo($form);
      $data.flow = 'custom_card_fields';
      paypal.HostedFields.render({
        createOrder: function() {
          return Drupal.paypalCheckout.makeCall(Drupal.paypalCheckout.onCreateUrl, {
            type: 'POST',
            contentType: "application/json; charset=utf-8",
            data: JSON.stringify($data)
          }).then(function(data) {
            $form.find("input[name$='[paypal_remote_id]']").val(data.id);
            return data.id;
          });
        },
        styles: {
          'input': {
            'color': '#3A3A3A',
            'transition': 'color 160ms linear',
            '-webkit-transition': 'color 160ms linear'
          },
          ':focus': {
            'color': '#333333'
          },
          '.invalid': {
            'color': '#FF0000'
          }
        },
        fields: {
          number: {
            selector: '#commerce-paypal-card-number',
            placeholder: Drupal.t('Card Number')
          },
          cvv: {
            selector: '#commerce-paypal-cvv',
            placeholder: Drupal.t('CVV')
          },
          expirationDate: {
            selector: '#commerce-paypal-expiration-date',
            placeholder: 'MM/YYYY'
          }
        }
      }).then(function(hostedFields) {
        $form.on('submit', function (event) {
          if ($(Drupal.paypalCheckout.cardFieldsSelector).length === 0) {
            return;
          }
          var message = '';
          // Disable the Continue button.
          $submit.attr("disabled", "disabled");
          event.preventDefault();
          $messagesContainer.html('');
          var state = hostedFields.getState();
          var formValid = Object.keys(state.fields).every(function(key) {
            var isValid = state.fields[key].isValid;
            if (!isValid) {
              message += Drupal.t('The @field you entered is invalid.', {'@field': key}) + '<br>';
            }
            return isValid;
          });

          if (!formValid) {
            message += Drupal.t('Please check your details and try again.');
            $messagesContainer.html(Drupal.theme('commercePaypalError', message));
            $submit.attr("disabled", false);
            return;
          }
          Drupal.paypalCheckout.addLoader();
          hostedFields.submit({
            contingencies: ['3D_SECURE']
          }).then(function(payload) {
            if (!payload.hasOwnProperty('orderId')) {
              message += Drupal.t('Please check your details and try again.');
              $messagesContainer.html(Drupal.theme('commercePaypalError', message));
              $submit.attr("disabled", false);
              Drupal.paypalCheckout.removeLoader();
            }
            else {
              event.currentTarget.submit();
            }
          });
        });
      });

    },
    initialize: function (context) {
      var waitForSdk = function() {
        if (typeof paypal !== 'undefined') {
          Drupal.paypalCheckout.renderForm(context);
        }
        else {
          setTimeout(function() {
            waitForSdk()
          }, 100);
        }
      };
      waitForSdk();
    },
    addLoader: function() {
      var $background = $('<div class="paypal-background-overlay"></div>');
      var $loader = $('<div class="paypal-background-overlay-loader"></div>');
      $background.append($loader);
      $('body').append($background);
    },
    removeLoader: function() {
      $('body').remove('.paypal-background-overlay');
    },
    extractBillingInfo: function ($form) {
      var billingInfo = {
        profile: null,
        address: {},
        profileCopy: false
      };

      // Check if the "profile copy" checkbox is present and checked. If so,
      // we first need to check if the shipping information pane is present in
      // the page. If it is, we need to use the address selected/entered.
      // In case the pane isn't present, we'll try to get the collect the
      // shipping profile using $order->collectProfiles().
      var $profileCopyCheckbox = $(':input[name="payment_information[add_payment_method][billing_information][copy_fields][enable]"]', $form);
      if ($profileCopyCheckbox.length && $profileCopyCheckbox.is(':checked')) {
        var shippingInfo = this.extractShippingInfo($form);
        if (shippingInfo.profile) {
          billingInfo.profile = shippingInfo.profile;
        }
        else if (!$.isEmptyObject(shippingInfo.address)) {
          billingInfo.address = shippingInfo.address;
        }
        else {
          billingInfo.profileCopy = true;
        }
        return billingInfo;
      }

      // Extract the billing information from the selected profile.
      $form.find(':input[name^="payment_information[add_payment_method][billing_information][address][0][address]"]').each(function() {
        // Extract the address field name.
        var name = jQuery(this).attr('name').split('[');
        name = name[name.length - 1];
        billingInfo.address[name.substring(0, name.length - 1)] = $(this).val();
      });

      // Fallback to the entered address, if the address fields are present.
      var $addressSelector = $('select[name="payment_information[add_payment_method][billing_information][select_address]"]', $form);
      if ($.isEmptyObject(billingInfo.address) && ($addressSelector.length && $addressSelector.val() !== '_new')) {
        billingInfo.profile = $addressSelector.val();
      }

      return billingInfo;
    },
    extractShippingInfo: function ($form) {
      var shippingInfo = {
        profile: null,
        address: {}
      };

      $form.find(':input[name^="shipping_information[shipping_profile][address][0][address]"]').each(function() {
        // Extract the address field name.
        var name = jQuery(this).attr('name').split('[');
        name = name[name.length - 1];
        shippingInfo.address[name.substring(0, name.length - 1)] = $(this).val();
      });

      var $addressSelector = $('select[name="shipping_information[shipping_profile][select_address]"', $form);
      if ($.isEmptyObject(shippingInfo.address) && ($addressSelector.length && $addressSelector.val() !== '_new')) {
        shippingInfo.profile = $addressSelector.val();
      }

      return shippingInfo;
    }
  };

  $(function () {
    $.extend(true, Drupal.paypalCheckout, settings.paypalCheckout);
    var script = document.createElement('script');
    script.src = Drupal.paypalCheckout.src;
    script.type = 'text/javascript';
    script.setAttribute('data-partner-attribution-id', 'Centarro_Commerce_PCP');
    script.setAttribute('data-client-token', Drupal.paypalCheckout.clientToken);
    document.getElementsByTagName('head')[0].appendChild(script);
  });

  Drupal.behaviors.commercePaypalCheckout = {
    attach: function (context) {
      Drupal.paypalCheckout.initialize(context);
    }
  };

  $.extend(Drupal.theme, /** @lends Drupal.theme */{
    commercePaypalError: function (message) {
      return $('<div role="alert">' +
        '<div class="messages messages--error">' + message + '</div>' +
        '</div>'
      );
    }
  });

})(jQuery, Drupal, drupalSettings);
