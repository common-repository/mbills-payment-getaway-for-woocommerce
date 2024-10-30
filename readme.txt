====================================== mBills Payment Gateway for WooCommerce ======================================
Author URI: https://www.mbills.si
Version: 1.0.0
Author: mBills
Tags: woocommerce, payment gateway, gateway, mbills, payments
Requires at least: 5.1
Tested up to: 5.8
License: GPLv3
Stable tag: 1.0.0


====================================== Description ======================================

This plugin allows your customers to make payments using mBills mobile wallet, provided by (http://www.mbills.si). 

After choosing this payment method, the customer is presented with payment info regarding the device:

1 - Desktop -
	If user uses Desktop, there is a popup with mBills QR code that user scan with mBills mobile wallet and pay. After successful payment or cancel payment, plugin automatically check tx and redirect user to successful page or back to cart if payment was not completed. 


2 - Mobile -
	If user uses mobile device, there is popup with "Pay with mBills" button that redirect user into mBills mobile wallet app, where can confirm or cancel the payment.



Before getting response from mBills, customer's order is considered "pending". If the transactions succeed, order is set to "processing" or different (configurable in the admin page of the plugin), otherwise to "failed".

This payment plugin also provide option of full refund of payment back to user mBills mobile wallet if transaction in mBills system already exists.

====================================== Frequently Asked Questions ======================================

=== What is API ans SECRET key?===

The merchant receives (requires) this credential after successful sign of contracts. This can be done in different ways. See:  https://www.mbills.si/za-podjetja/
Those credentials are essential for calling our service and to make plugin to work.


====================================== Screenshots ======================================
1. Payment methods with mBills Payment Gateway
2. mBills Payment Gateway popup with QR code - desktop    
3. mBills Payment Gateway popup with button - mobile
4. Plugin Settings

====================================== Installation ======================================
1. Upload the entire `mbills-payment-getaway-for-woocommerce` folder to the `/wp-content/plugins/` directory or install through WordPress directly.
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **WooCommerce &gt; Settings &gt; Checkout** and select 'mBills'
4. Change currency, language and decimal places if necessary
5. Insert API and SECRET key in the settings page of the plugin


======================================Changelog======================================
= 2021-10-14 - version 1.0.0 =
 * Initial Release
