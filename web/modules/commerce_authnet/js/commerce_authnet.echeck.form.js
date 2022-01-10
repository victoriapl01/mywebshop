/**
 * @file
 * Javascript handle Authorize.net accept.js eChecks.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  /**
   * Authorize.net eCheck for js.
   */
  Drupal.commerceAuthorizeNetEcheckForm = function($form, settings) {
    // Sends the card data to Authorize.Net and receive the payment nonce in
    // response.
    var sendPaymentDataToAnet = function (event) {
      var secureData = {};
      var authData = {};
      var bankData = {};

      // Extract the card number, expiration date, and card code.
      bankData.routingNumber = $('.authnet-echeck-routing-number').val();
      bankData.accountNumber = $('.authnet-echeck-account-number').val();
      bankData.nameOnAccount = $('.authnet-echeck-name-on-account').val();
      bankData.accountType = $('.authnet-echeck-account-type').val();
      secureData.bankData = bankData;

      // The Authorize.Net Client Key is used in place of the traditional
      // Transaction Key. The Transaction Key is a shared secret and must
      // never be exposed. The Client Key is a public key suitable for use
      // where someone outside the merchant might see it.
      authData.clientKey = settings.clientKey;
      authData.apiLoginID = settings.apiLoginID;
      secureData.authData = authData;

      // Pass the card number and expiration date to Accept.js for submission
      // to Authorize.Net.
      Accept.dispatchData(secureData, responseHandler);
    };

    // Process the response from Authorize.Net to retrieve the two elements
    // of the payment nonce.  If the data looks correct, record the
    // OpaqueData to the console and call the transaction processing function.
    var responseHandler = function (response) {
      if (response.messages.resultCode === 'Error') {
        for (var i = 0; i < response.messages.message.length; i++) {
          Drupal.behaviors.commerceAuthorizeNetForm.errorDisplay(response.messages.message[i].code, response.messages.message[i].text);
        }
      }
      else {

        $('.accept-js-data-descriptor', $form).val(response.opaqueData.dataDescriptor);
        $('.accept-js-data-value', $form).val(response.opaqueData.dataValue);

        // Clear out the values so they don't get posted to Drupal. They
        // would never be used, but for "PCI compliance" we should never send
        // them at all. (" used because PCI compliance is not applicable for
        // eChecks.)
        $('.authnet-echeck-routing-number').val('');
        $('.authnet-echeck-account-number').val('');
        $('.authnet-echeck-name-on-account').val('');
        $('.authnet-echeck-account-type').val('');

        // Submit the form.
        $form.trigger('submit', { 'populated': true });
      }
    };

    // Form submit.
    $form.on('submit.authnet', function (event, options) {
      // Disable the submit button to prevent repeated clicks.
      $form.find('.button--primary').prop('disabled', true);
      options = options || {};
      if (options.populated) {
        return;
      }
      event.preventDefault();
      // Send payment data to anet.
      sendPaymentDataToAnet(event);

      // Prevent the form from submitting with the default action.
      return false;
    });
  };

})(jQuery, Drupal, drupalSettings);
