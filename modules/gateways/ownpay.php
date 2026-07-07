<?php
/**
 * OwnPay Payment Gateway Module for WHMCS
 *
 * Architecture:
 *   - ownpay_link()          Creates a payment intent via the OwnPay API and redirects
 *                            the customer to the OwnPay hosted checkout page. Nothing else.
 *   - callback/ownpay.php    Handles all post-payment logic:
 *                              GET ?action=return  Customer redirect back from OwnPay
 *                              POST                Server-to-server webhook notification
 *
 * OwnPay automatically appends ?payment_id=<uuid>&status=<status> to the redirect_url
 * when the customer returns from checkout (see checkout-status.js in OwnPay source).
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 * @copyright Copyright (c) OwnPay
 * @license https://opensource.org/licenses/MIT MIT License
 * @version 1.2.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// ---------------------------------------------------------------------------
// Module Registration
// ---------------------------------------------------------------------------

function ownpay_MetaData(): array
{
    return [
        'DisplayName'                => 'OwnPay Payment Gateway',
        'APIVersion'                 => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'           => false,
        'description'                => 'Accept payments via OwnPay checkout.',
        'category'                   => 'Payment Gateway',
        'author'                     => 'OwnPay',
        'authorURL'                  => 'https://ownpay.org',
        'support'                    => 'https://support.ownpay.org',
        'version'                    => '1.0.0',
    ];
}

function ownpay_config(): array
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'OwnPay Payment Gateway',
        ],
        'base_url' => [
            'FriendlyName' => 'OwnPay Base URL',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Your OwnPay instance URL, e.g. <strong>https://pay.example.com</strong>. No trailing slash.',
        ],
        'api_key' => [
            'FriendlyName' => 'API Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'Your OwnPay Merchant API key. Found in OwnPay Admin &rarr; Developer Hub &rarr; API Keys.',
        ],
        'webhook_secret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type'         => 'password',
            'Size'         => '60',
            'Default'      => '',
            'Description'  => 'HMAC-SHA256 secret configured on the OwnPay webhook. '
                . 'Webhook URL: <strong>{your-whmcs-url}/modules/gateways/callback/ownpay.php</strong>',
        ],
        'test_mode' => [
            'FriendlyName' => 'Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Log all API requests and responses to the WHMCS Gateway Log for debugging.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Make a JSON API request to OwnPay.
 *
 * @return array{body: string, http_code: int, error: string, errno: int}
 */
function ownpay_apiRequest(
    string $method,
    string $url,
    #[\SensitiveParameter] string $apiKey,
    ?array $payload = null,
    int $timeout = 30,
): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }
    }

    $body     = (string) curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    return ['body' => $body, 'http_code' => $httpCode, 'error' => $error, 'errno' => $errno];
}

/**
 * Safely decode a JSON response body into an associative array.
 */
function ownpay_parseJson(string $body): array
{
    if ($body === '' || !json_validate($body)) {
        return [];
    }

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Collect all human-readable error messages from an API error response.
 *
 * @return string[]
 */
function ownpay_extractErrors(array $data): array
{
    $messages = [];

    if (!empty($data['error']) && is_string($data['error'])) {
        $messages[] = $data['error'];
    }

    if (!empty($data['message']) && is_string($data['message'])) {
        $messages[] = $data['message'];
    }

    foreach ($data['errors'] ?? [] as $err) {
        if (!empty($err['message']) && is_string($err['message'])) {
            $messages[] = $err['message'];
        }
    }

    return array_unique($messages);
}

/**
 * Wrap a plain-text error message in a Bootstrap danger alert.
 */
function ownpay_errorHtml(string $message): string
{
    return '<div class="alert alert-danger">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '</div>';
}

// ---------------------------------------------------------------------------
// Core WHMCS Gateway Functions
// ---------------------------------------------------------------------------

/**
 * Generate the payment button / redirect.
 *
 * Called by WHMCS when rendering the invoice payment section. Creates a new
 * payment intent via the OwnPay API and immediately redirects the customer
 * to the OwnPay hosted checkout page.
 *
 * After payment, OwnPay redirects the customer back to redirect_url with
 *   ?payment_id=<uuid>&status=<status>
 * appended automatically. The callback/ownpay.php handler processes that
 * return, verifies payment status via the API, and marks the invoice as paid.
 */
function ownpay_link(array $params): string
{
    $baseUrl    = rtrim((string) ($params['base_url'] ?? ''), '/');
    $apiKey     = (string) ($params['api_key'] ?? '');
    $testMode   = !empty($params['test_mode']);

    $invoiceId    = (int) ($params['invoiceid'] ?? 0);
    $amount       = number_format((float) ($params['amount'] ?? 0), 2, '.', '');
    $currencyCode = strtoupper((string) ($params['currency'] ?? 'USD'));

    $firstname = (string) ($params['clientdetails']['firstname'] ?? '');
    $lastname  = (string) ($params['clientdetails']['lastname'] ?? '');
    $email     = (string) ($params['clientdetails']['email'] ?? '');
    $phone     = (string) ($params['clientdetails']['phonenumber'] ?? '');

    $systemUrl  = rtrim((string) ($params['systemurl'] ?? ''), '/');
    $returnUrl  = (string) ($params['returnurl'] ?? '');
    $moduleName = (string) ($params['paymentmethod'] ?? 'ownpay');

    if ($baseUrl === '' || $apiKey === '') {
        return ownpay_errorHtml('OwnPay gateway is not configured. Please set the Base URL and API Key in the gateway settings.');
    }


    $currentProtocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $currentHost     = $_SERVER['HTTP_HOST'] ?? '';
    if ($currentHost !== '') {
        $callbackBase = $currentProtocol . '://' . $currentHost . '/modules/gateways/callback/' . $moduleName . '.php';
    } else {
        $callbackBase = $systemUrl . '/modules/gateways/callback/' . $moduleName . '.php';
    }
    $redirectUrl  = $callbackBase
        . '?action=return'
        . '&invoice_id=' . $invoiceId
        . '&return_url=' . urlencode($returnUrl);

    $customerName = trim($firstname . ' ' . $lastname);

    $payload = [
        'amount'       => $amount,
        'currency'     => $currencyCode,
        'redirect_url' => $redirectUrl,
        'cancel_url'   => $returnUrl,           // On cancel, go straight back to WHMCS invoice
        'callback_url' => $callbackBase,        // Server-to-server webhook (POST)
        'reference'    => (string) $invoiceId,
        'metadata'     => [
            'whmcs_invoice_id' => (string) $invoiceId,
            'whmcs_client_id'  => (string) ($params['clientdetails']['id'] ?? ''),
            'module_version'   => '1.2.0',
            'whmcs_version'    => (string) ($params['whmcsVersion'] ?? 'unknown'),
        ],
    ];

    if ($customerName !== '') {
        $payload['customer_name'] = $customerName;
    }
    if ($email !== '') {
        $payload['customer_email'] = $email;
    }
    if ($phone !== '') {
        $payload['customer_phone'] = $phone;
    }

    // --- Initiate payment ---
    $resp = ownpay_apiRequest('POST', $baseUrl . '/api/v1/payments', $apiKey, $payload);

    if ($resp['errno'] !== 0) {
        if ($testMode) {
            logTransaction($params['name'], [
                'event'   => 'initiate',
                'error'   => $resp['error'],
                'errno'   => $resp['errno'],
                'url'     => $baseUrl . '/api/v1/payments',
                'payload' => $payload,
            ], 'Connection Error');
        }
        return ownpay_errorHtml('Unable to connect to OwnPay: ' . $resp['error']);
    }

    $data = ownpay_parseJson($resp['body']);

    if (empty($data['success']) || $resp['http_code'] !== 201) {
        $errors   = ownpay_extractErrors($data);
        $errorMsg = $errors !== []
            ? implode(' ', $errors)
            : 'Payment initiation failed. Please try again or contact support.';
        if ($testMode) {
            logTransaction($params['name'], [
                'event'     => 'initiate',
                'http_code' => $resp['http_code'],
                'response'  => $data,
                'payload'   => $payload,
            ], 'API Error');
        }
        return ownpay_errorHtml($errorMsg);
    }

    $checkoutUrl = (string) ($data['data']['checkout_url'] ?? '');
    $paymentId   = (string) ($data['data']['payment_id'] ?? '');

    if ($checkoutUrl === '') {
        if ($testMode) {
            logTransaction($params['name'], ['event' => 'initiate', 'response' => $data], 'Missing Checkout URL');
        }
        return ownpay_errorHtml('OwnPay did not return a checkout URL. Please contact support.');
    }

    if ($testMode) {
        logTransaction($params['name'], [
            'event'        => 'initiate',
            'payment_id'   => $paymentId,
            'checkout_url' => $checkoutUrl,
            'amount'       => $amount,
            'currency'     => $currencyCode,
            'invoice_id'   => $invoiceId,
        ], 'Payment Intent Created');
    }

    $langPayNow = ($params['langpaynow'] ?? '') ?: 'Pay Now';

    // Auto-redirect the customer to the OwnPay checkout page.
    // The <form> acts as a no-JS fallback button.
    return '<form method="get" action="' . htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="submit" class="btn btn-primary" value="' . htmlspecialchars($langPayNow, ENT_QUOTES, 'UTF-8') . '" />'
        . '</form>'
        . '<script>window.location.href=' . json_encode($checkoutUrl, JSON_THROW_ON_ERROR) . ';</script>';
}

// ---------------------------------------------------------------------------
// Refund Support
// ---------------------------------------------------------------------------

function ownpay_refund(array $params): array
{
    $baseUrl  = rtrim((string) ($params['base_url'] ?? ''), '/');
    $apiKey   = (string) ($params['api_key'] ?? '');
    $testMode = !empty($params['test_mode']);

    $transactionId = (string) ($params['transid'] ?? '');
    $refundAmount  = number_format((float) ($params['amount'] ?? 0), 2, '.', '');
    $currencyCode  = (string) ($params['currency'] ?? '');

    if ($baseUrl === '' || $apiKey === '') {
        return [
            'status'  => 'error',
            'rawdata' => 'OwnPay gateway is not configured (missing Base URL or API Key).',
            'transid' => '',
            'fees'    => 0,
        ];
    }

    $resp = ownpay_apiRequest('POST', $baseUrl . '/api/v1/refunds', $apiKey, [
        'trx_id' => $transactionId,
        'amount' => $refundAmount,
    ]);

    if ($resp['errno'] !== 0) {
        // Error-path log always fires regardless of test_mode.
        logTransaction($params['name'], [
            'event' => 'refund',
            'error' => $resp['error'],
            'errno' => $resp['errno'],
        ], 'Refund Connection Error');

        return ['status' => 'error', 'rawdata' => ['error' => $resp['error']], 'transid' => '', 'fees' => 0];
    }

    $data = ownpay_parseJson($resp['body']);

    if ($resp['http_code'] !== 201 || empty($data['success'])) {
        logTransaction($params['name'], ['event' => 'refund', 'response' => $data], 'Refund API Error');

        return ['status' => 'declined', 'rawdata' => $data, 'transid' => '', 'fees' => 0];
    }

    $refundData    = $data['data'] ?? [];
    $refundTransId = (string) ($refundData['uuid'] ?? $refundData['trx_id'] ?? $refundData['id'] ?? '');
    $refundFee     = (float) ($refundData['fee'] ?? 0);
    $refundStatus  = (string) ($refundData['status'] ?? 'pending');

    $whmcsStatus = in_array($refundStatus, ['failed', 'cancelled', 'expired'], true)
        ? 'declined'
        : 'success';

    // Verbose success log gated on test_mode to avoid flooding production Gateway Log.
    if ($testMode) {
        logTransaction($params['name'], [
            'event'           => 'refund',
            'refund_id'       => $refundTransId,
            'original_trx_id' => $transactionId,
            'amount'          => $refundAmount,
            'currency'        => $currencyCode,
            'status'          => $refundStatus,
        ], 'Refund ' . ucfirst($refundStatus));
    }

    return [
        'status'  => $whmcsStatus,
        'rawdata' => $data,
        'transid' => $refundTransId,
        'fees'    => $refundFee,
    ];
}

// ---------------------------------------------------------------------------
// Transaction Information (Admin area "View Details")
// ---------------------------------------------------------------------------

function ownpay_TransactionInformation(array $params = []): ?\WHMCS\Billing\Payment\Transaction\Information
{
    $baseUrl = rtrim((string) ($params['base_url'] ?? ''), '/');
    $apiKey  = (string) ($params['api_key'] ?? '');
    $txId    = (string) ($params['transactionId'] ?? '');

    if ($baseUrl === '' || $apiKey === '' || $txId === '') {
        return null;
    }

    $resp = ownpay_apiRequest('GET', $baseUrl . '/api/v1/transactions/' . urlencode($txId), $apiKey, timeout: 15);

    if ($resp['errno'] !== 0 || $resp['http_code'] !== 200) {
        return null;
    }

    $data = ownpay_parseJson($resp['body']);

    if (empty($data['success']) || empty($data['data'])) {
        return null;
    }

    $txn = $data['data'];

    try {
        $info = new \WHMCS\Billing\Payment\Transaction\Information();

        if (!empty($txn['trx_id']))    $info->setTransactionId((string) $txn['trx_id']);
        if (!empty($txn['amount']))    $info->setAmount((float) $txn['amount']);
        if (!empty($txn['currency']))  $info->setCurrency((string) $txn['currency']);
        if (!empty($txn['status']))    $info->setStatus((string) $txn['status']);
        if (!empty($txn['fee']))       $info->setFee((float) $txn['fee']);
        if (!empty($txn['gateway']))   $info->setType((string) $txn['gateway']);
        if (!empty($txn['created_at'])) {
            $info->setCreated(\WHMCS\Carbon::parse((string) $txn['created_at']));
        }

        foreach ([
            'ownpay_gateway_trx_id' => 'gateway_trx_id',
            'ownpay_payment_method' => 'method',
        ] as $label => $key) {
            if (!empty($txn[$key])) {
                $info->setAdditionalDatum($label, (string) $txn[$key]);
            }
        }

        if (!empty($txn['net_amount'])) {
            $info->setAdditionalDatum(
                'ownpay_net_amount',
                $txn['net_amount'] . ' ' . ($txn['currency'] ?? ''),
            );
        }

        return $info;
    } catch (\Throwable $e) {
        if ($params['test_mode'] ?? false) {
            error_log('OwnPay TransactionInformation error: ' . $e->getMessage());
        }
        return null;
    }
}
