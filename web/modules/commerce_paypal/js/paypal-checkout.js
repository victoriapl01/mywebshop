(function ($, Drupal) {
  'use strict';

  Drupal.paypalCheckout = {
    initialized: false,
    makeCall: function(url, settings) {
      settings = settings || {};
      var ajaxSettings = {
        dataType: 'json',
        url: url
      };
      $.extend(ajaxSettings, settings);
      return $.ajax(ajaxSettings);
    },
    renderButtons: function(settings) {
      paypal.Buttons({
        createOrder: function() {
          return Drupal.paypalCheckout.makeCall(settings.onCreateUrl, {
            type: 'POST',
            contentType: "application/json; charset=utf-8",
            data: JSON.stringify({
              flow: settings.flow
            })
          }).then(function(data) {
            return data.id;
          });
        },
        onApprove: function (data) {
          Drupal.paypalCheckout.addLoader();
          return Drupal.paypalCheckout.makeCall(settings.onApproveUrl).then(function(data) {
            if (data.hasOwnProperty('redirectUrl')) {
              window.location.assign(data.redirectUrl);
            }
            else {
              // Force a reload to see the eventual error messages.
              location.reload();
            }
          });
        },
        style: settings['style']
      }).render('#' + settings['elementId']);
    },
    initialize: function (settings) {
      if (!this.initialized) {
        // Ensure we initialize the script only once.
        this.initialized = true;
        var script = document.createElement('script');
        script.src = settings.src;
        script.type = 'text/javascript';
        script.setAttribute('data-partner-attribution-id', 'CommerceGuys_Cart_SPB');
        document.getElementsByTagName('head')[0].appendChild(script);
      }
      var waitForSdk = function(settings) {
        if (typeof paypal !== 'undefined') {
          Drupal.paypalCheckout.renderButtons(settings);
        }
        else {
          setTimeout(function() {
            waitForSdk(settings)
          }, 100);
        }
      };
      waitForSdk(settings);
    },
    addLoader: function() {
      var $background = $('<div class="paypal-background-overlay"></div>');
      var $loader = $('<div class="paypal-background-overlay-loader"></div>');
      $background.append($loader);
      $('body').append($background);
    }
  };

  Drupal.behaviors.commercePaypalCheckout = {
    attach: function (context, settings) {
      $.each(settings.paypalCheckout, function(key, value) {
        $('#' + value['elementId']).once('paypal-checkout-init').each(function() {
          Drupal.paypalCheckout.initialize(value);
        });
      });
    }
  };

})(jQuery, Drupal);
