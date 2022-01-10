Commerce Authorize.net
======================
[![Build Status](https://travis-ci.org/drupalcommerce/commerce_authnet.svg?branch=8.x-1.x)](https://travis-ci.org/drupalcommerce/commerce_authnet)

This project provides Commerce 2.x integration for Authorize.Net.

CONTENTS OF THIS FILE
---------------------
* Introduction
* Requirements
* Installation
* Configuration
* Testing
* Note about supported credit card types

INTRODUCTION
------------
The project currently integrates Authorize.Net via the [Accept.js](https://developer.authorize.net/api/reference/features/acceptjs.html)
JavaScript library for sending secure payment data directly to Authorize.Net.

* For a full description of the module, visit the project documentation page:
  https://www.drupal.org/docs/contributed-modules/commerce-authorizenet
* To submit bug reports and feature suggestions, or to track changes:
  https://www.drupal.org/project/commerce_authnet

REQUIREMENTS
------------
This module requires the following:
* Commerce Core 2.x
* An account with Authorize.Net
* If you want to use Authorize.Net in test mode, then you'll need to sign up
  for an Authorize.Net sandbox account - https://developer.authorize.net/hello_world/sandbox.html

INSTALLATION
------------
* This module needs to be installed via Composer, which will download the required libraries.
composer require "drupal/commerce_authnet"
https://www.drupal.org/docs/8/extending-drupal-8/installing-modules-composer-dependencies

CONFIGURATION
-------------
* Please see the documentation for each payment plugin here - https://www.drupal.org/docs/contributed-modules/commerce-authorizenet

Testing
-------------
To test your implementation, add a product to your cart, proceed thorough checkout
and enter a credit card. We recommend using a sandbox account for this before
attempting to go live. If you're using a sandbox account, you can use the following
credit card to test a transaction

###### Visa Card
Credit Card #: 4111 1111 1111 1111
Expiration Date: Any 4 digit expiration greater than today's date
CVC (if enabled): Any 3 digit code

###### Master Card
Credit Card #: 5105105105105100
Expiration Date: Any 4 digit expiration greater than today's date
CVC (if enabled): Any 3 digit code

###### American Express
Credit Card #: 371449635398431
Expiration Date: Any 4 digit expiration greater than today's date
CVC (if enabled): Any 3 digit code

###### Discover
Credit Card #: 6011111111111117
Expiration Date: Any 4 digit expiration greater than today's date
CVC (if enabled): Any 3 digit code

Note about supported credit card types
--------------------------------------
Authorize.Net has the ability to process Visa, MasterCard, Discover,
American Express, Diners Club, and JCB. As per the gateway documentation only
MasterCard and Visa are accepted by default. You have to place a support
request to Authorize.Net to accept Amex and Discover cards.
Please goto https://support.authorize.net/s/article/How-can-I-add-remove-credit-card-types-that-I-can-accept for more information.
