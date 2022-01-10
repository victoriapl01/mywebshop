/**
 * @file
 * Javascript to generate Accept.js token in PCI-compliant way.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * Authorize.net accept.js form js.
   */
  Drupal.commerceAuthorizeNetAcceptForm = function ($form, settings) {
    var last4 = '';
    // To be used to temporarily store month and year.
    var expiration = {};
    var responseJwt = '';

    $form.find('.button--primary').prop('disabled', false);

    if (settings.ccaStatus == 1) {
      if (settings.mode == 'test') {
        Cardinal.configure({
          logging: {
            level: "on"
          }
        });
      }
      Cardinal.setup("init", {
        jwt: $('.accept-js-data-cca-jwt-token').val()
      });
    }

    // Sends the card data to Authorize.Net and receive the payment nonce in
    // response.
    var sendPaymentDataToAnet = function (event) {
      var secureData = {};
      var authData = {};
      var cardData = {};
      // Extract the card number, expiration date, and card code.
      cardData.cardNumber = $('#credit-card-number').val().replace(/ /g, "");
      cardData.month = $('#expiration-month').val();
      cardData.year = $('#expiration-year').val();
      cardData.cardCode = $('#cvv').val();
      secureData.cardData = cardData;

      // The Authorize.Net Client Key is used in place of the traditional
      // Transaction Key. The Transaction Key is a shared secret and must never
      // be exposed. The Client Key is a public key suitable for use where
      // someone outside the merchant might see it.
      authData.clientKey = settings.clientKey;
      authData.apiLoginID = settings.apiLoginID;
      secureData.authData = authData;

      if (settings.ccaStatus == 1) {
        var order = {
          OrderDetails: {
            OrderNumber: settings.orderId,
            Amount: settings.orderAmount,
            CurrencyCode: settings.orderCurrency
          },
          Consumer: {
            Account: {
              AccountNumber: cardData.cardNumber,
              ExpirationMonth: cardData.month,
              ExpirationYear: "20" + cardData.year,
              CardCode: cardData.cardCode,
            }
          }
        };
        Cardinal.start("cca", order);

        Cardinal.on('payments.validated', function (data, jwt) {
          try {
            $.ajax({
              method: 'post',
              url: '/admin/commerce-authnet/cca-validation.json',
              data: {
                'responseJwt': jwt,
                'gatewayId': settings.gatewayId
              },
              dataType: 'json'
            }).done(function (responseData) {
                if (responseData !== undefined && typeof responseData === 'object' && responseData.verified) {
                  if ('ActionCode' in data) {
                    switch (data.ActionCode) {
                      case "SUCCESS":
                      case "NOACTION":
                        // Success indicates that we got back CCA values we can pass to the gateway
                        // No action indicates that everything worked, but there is no CCA values to worry about, so we can move on with the transaction
                        console.warn('The transaction was completed with no errors.', data.Payment.ExtendedData);

                        responseJwt = jwt;
                        // CCA Succesful, now complete the transaction with Authorize.Net
                        Accept.dispatchData(secureData, responseHandler);
                        break;

                      case "FAILURE":
                        // Failure indicates the authentication attempt failed
                        console.warn('The authentication attempt failed.', data.Payment);
                        alert('The authentication attempt failed.')
                        $form.find('.button--primary').prop('disabled', false);
                        break;

                      case "ERROR":
                      default:
                        // Error indicates that a problem was encountered at some point in the transaction
                        console.warn('An issue occurred with the transaction.', data.Payment);
                        alert('An issue occurred with the transaction.')
                        $form.find('.button--primary').prop('disabled', false);
                        break;
                    }
                  }
                  else {
                    console.error("Failure while attempting to verify JWT signature: ", data);
                    alert('Failure while attempting to verify JWT signature.');
                    $form.find('.button--primary').prop('disabled', false);
                  }
                }
                else {
                  console.error('Response data was incorrectly formatted: ', responseData);
                  alert('Response data was incorrectly formatted.');
                  $form.find('.button--primary').prop('disabled', false);
                }
              })
              .fail(function (xhr, ajaxError) {
                console.log('Connection failure:', ajaxError);
                alert('Connection failure.');
                $form.find('.button--primary').prop('disabled', false);
              });
          } catch (validateError) {
            console.error('Failed while processing validate.', validateError);
            alert('Failed while processing validate.');
            $form.find('.button--primary').prop('disabled', false);
          }
        });
      }
      else {
        // Pass the card number and expiration date to Accept.js for submission
        // to Authorize.Net.
        Accept.dispatchData(secureData, responseHandler);
      }
    };

    // Process the response from Authorize.Net to retrieve the two elements of
    // the payment nonce. If the data looks correct, record the OpaqueData to
    // the console and call the transaction processing function.
    var responseHandler = function (response) {
      if (response.messages.resultCode === 'Error') {
        for (var i = 0; i < response.messages.message.length; i++) {
          Drupal.behaviors.commerceAuthorizeNetForm.errorDisplay(response.messages.message[i].code, response.messages.message[i].text);
        }
      }
      else {
        processTransactionDataFromAnet(response.opaqueData);
      }
    };

    var processTransactionDataFromAnet = function (responseData) {
      $('.accept-js-data-descriptor', $form).val(responseData.dataDescriptor);
      $('.accept-js-data-value', $form).val(responseData.dataValue);

      $('.accept-js-data-last4', $form).val(last4);
      $('.accept-js-data-month', $form).val(expiration.month);
      $('.accept-js-data-year', $form).val('20' + expiration.year);
      if (settings.ccaStatus == 1) {
        $('.accept-js-data-cca-jwt-response-token', $form).val(responseJwt);
      }

      // Clear out the values so they don't get posted to Drupal. They would
      // never be used, but for PCI compliance we should never send them at.
      $('#credit-card-number').val('');
      $('#expiration-month').val('');
      $('#expiration-year').val('');
      $('#cvv').val('');

      // Submit the form.
      var $primaryButton = $form.find(':input.button--primary');
      $form.append('<input type="hidden" name="_triggering_element_name" value="' + $primaryButton.attr('name') + '" />');
      $form.append('<input type="hidden" name="_triggering_element_value" value="' + $primaryButton.val() + '" />');
      $primaryButton.prop('disabled', false);
      $form.trigger('submit', { 'populated' : true });
    };

    // Form submit.
    $form.on('submit.authnet', function (event, options) {
      options = options || {};
      if (options.populated) {
        return;
      }
      // Disable the submit button to prevent repeated clicks.
      $form.find(':input.button--primary').prop('disabled', true);
      event.preventDefault();

      // Store last4 digit.
      var credit_card_number = $('#credit-card-number').val();
      last4 = credit_card_number.substr(credit_card_number.length - 4);
      expiration = {
        month: $('#expiration-month').val(),
        year: $('#expiration-year').val()
      };

      // Send payment data to anet.
      sendPaymentDataToAnet(event);
      return false;
    });
  };

})(jQuery, Drupal, drupalSettings);
