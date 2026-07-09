<?php
/**
 * OwnPay Payment Gateway Module - English Language File
 *
 * @copyright Copyright (c) OwnPay
 * @license https://opensource.org/licenses/MIT MIT License
 */

// Configuration field labels and descriptions
$_LANG['ownpay']['config']['base_url']           = 'OwnPay Base URL';
$_LANG['ownpay']['config']['base_url_desc']      = 'Enter your OwnPay instance URL (e.g. https://pay.example.com). No trailing slash.';
$_LANG['ownpay']['config']['api_key']            = 'API Key';
$_LANG['ownpay']['config']['api_key_desc']       = 'Your OwnPay API key (starts with op_). Found in OwnPay Admin > Developer Hub > API Keys.';
$_LANG['ownpay']['config']['webhook_secret']     = 'Webhook Secret';
$_LANG['ownpay']['config']['webhook_secret_desc'] = 'HMAC-SHA256 secret used to verify webhook signatures. Configure this in OwnPay Admin > Developer Hub > Webhooks.';
$_LANG['ownpay']['config']['logo_url']           = 'Gateway Logo URL';
$_LANG['ownpay']['config']['logo_url_desc']      = 'Optional: URL to a custom logo image displayed on the checkout page. Leave blank for default.';
$_LANG['ownpay']['config']['test_mode']          = 'Test Mode';
$_LANG['ownpay']['config']['test_mode_desc']     = 'Enable test mode to log API requests and responses for debugging.';

// Error messages
$_LANG['ownpay']['error']['not_configured']     = 'OwnPay gateway is not configured. Please contact the administrator.';
$_LANG['ownpay']['error']['connection_failed']  = 'Connection to OwnPay failed. Please try again later.';
$_LANG['ownpay']['error']['invalid_response']   = 'Invalid response from OwnPay. Please try again later.';
$_LANG['ownpay']['error']['payment_failed']     = 'Payment initiation failed. Please try again.';
$_LANG['ownpay']['error']['no_checkout_url']    = 'OwnPay did not return a checkout URL. Please contact support.';

// Transaction information labels
$_LANG['ownpay']['transaction']['gateway_trx_id']    = 'OwnPay Transaction ID';
$_LANG['ownpay']['transaction']['payment_method']    = 'Payment Method';
$_LANG['ownpay']['transaction']['net_amount']        = 'Net Amount';
