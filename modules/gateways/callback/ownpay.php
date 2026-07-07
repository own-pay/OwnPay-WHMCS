<?php
/**
 * OwnPay Payment Gateway Callback Handler for WHMCS
 *
 * This single file handles two entry points:
 *
 *   GET  ?action=return  Customer redirect from OwnPay after checkout.
 *                        OwnPay automatically appends &payment_id=<uuid>&status=<status>
 *                        to whatever redirect_url was set on the payment intent.
 *                        We verify the payment status via the OwnPay API (never trust
 *                        the GET param alone), apply payment to the WHMCS invoice,
 *                        and redirect the customer to the invoice page.
 *
 *   POST (no action)     Server-to-server webhook from OwnPay. HMAC-SHA256 verified,
 *                        then applies the payment to the invoice if status is completed.
 *
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = basename(__FILE__, '.php'); // 'ownpay'
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    die('Module Not Activated');
}

$baseUrl  = rtrim((string) ($gatewayParams['base_url'] ?? ''), '/');
$apiKey   = (string) ($gatewayParams['api_key'] ?? '');
$testMode = !empty($gatewayParams['test_mode']);

// ===========================================================================
// ROUTE 1: GET ?action=return - Customer redirect back from OwnPay checkout
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'return') {

    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    $returnUrl = (string) ($_GET['return_url'] ?? '');

    // OwnPay appends these automatically (see checkout-status.js in OwnPay source).
    $paymentId = (string) ($_GET['payment_id'] ?? '');
    $getStatus = strtolower((string) ($_GET['status'] ?? ''));

    // Always redirect the customer somewhere - fall back to invoice list if returnUrl is missing.
    $systemUrl  = rtrim((string) ($gatewayParams['systemurl'] ?? ''), '/');
    $redirectTo = $returnUrl !== '' ? $returnUrl : $systemUrl . '/clientarea.php?action=invoices';

    // --- Guard: must have a valid invoice ---
    if ($invoiceId <= 0) {
        logTransaction($gatewayParams['name'], [
            'event' => 'return',
            'error' => 'Missing or invalid invoice_id in return URL',
        ], 'Return - Invalid Invoice');
        header('Location: ' . $redirectTo);
        exit;
    }

    // --- Guard: gateway must be configured ---
    if ($baseUrl === '' || $apiKey === '') {
        logTransaction($gatewayParams['name'], [
            'event' => 'return',
            'error' => 'Gateway not configured (missing base_url or api_key)',
        ], 'Return - Config Error');
        header('Location: ' . $redirectTo);
        exit;
    }

    // --- Guard: must have a payment_id to verify against ---
    if ($paymentId === '') {
        // Customer may have navigated here manually, or cancelled before payment was initiated.
        logTransaction($gatewayParams['name'], [
            'event'      => 'return',
            'invoice_id' => $invoiceId,
            'status_get' => $getStatus,
            'note'       => 'No payment_id in return URL - possible cancellation or direct visit',
        ], 'Return - No Payment ID');
        header('Location: ' . $redirectTo);
        exit;
    }

    // --- Fast-path skip: status param says it was not successful ---
    // We still verify via API below for any success case - never trust GET params alone.
    if ($getStatus !== '' && !in_array($getStatus, ['completed', 'paid', 'success'], true)) {
        logTransaction($gatewayParams['name'], [
            'event'      => 'return',
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'status_get' => $getStatus,
            'note'       => 'Payment not completed per redirect status param',
        ], 'Return - ' . ucfirst($getStatus));
        header('Location: ' . $redirectTo);
        exit;
    }

    // --- Verify payment status authoritatively via OwnPay API ---
    $detailResp = ownpay_cb_apiGet($baseUrl . '/api/v1/payments/' . urlencode($paymentId), $apiKey);

    if ($detailResp['errno'] !== 0 || $detailResp['http_code'] !== 200) {
        logTransaction($gatewayParams['name'], [
            'event'      => 'return',
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'errno'      => $detailResp['errno'],
            'error'      => $detailResp['error'],
        ], 'Return - API Lookup Error');
        header('Location: ' . $redirectTo);
        exit;
    }

    $detail  = ownpay_cb_parseJson($detailResp['body']);
    $pData   = $detail['data'] ?? [];
    $apiStatus = strtolower((string) ($pData['status'] ?? ''));
    $trxId     = (string) ($pData['trx_id'] ?? '');
    $amount    = (string) ($pData['amount'] ?? '');
    $fee       = (string) ($pData['fee'] ?? '0');

    // Only 'completed' is the terminal success state per the OwnPay API spec.
    if ($apiStatus !== 'completed') {
        logTransaction($gatewayParams['name'], [
            'event'      => 'return',
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'api_status' => $apiStatus,
            'note'       => 'Payment not completed per API - no action taken',
        ], 'Return - ' . ($apiStatus !== '' ? ucfirst($apiStatus) : 'Not Completed'));
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($trxId === '') {
        // Completed but no trx_id yet (extremely unlikely) - webhook will handle it.
        logTransaction($gatewayParams['name'], [
            'event'      => 'return',
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'note'       => 'Status completed but trx_id missing - webhook will apply payment',
        ], 'Return - Missing trx_id');
        header('Location: ' . $redirectTo);
        exit;
    }

    // --- Apply payment to WHMCS invoice ---
    // checkCbInvoiceID: validates the invoice exists and belongs to this gateway; exits on failure.
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

    // Do NOT use checkCbTransID here: it terminates the request on duplicate detection,
    // which would prevent the HTTP redirect that returns the customer to the invoice page.
    // addInvoicePayment handles duplicate trx_id gracefully (no double-credit is applied).
    addInvoicePayment(
        $invoiceId,
        $trxId,
        (float) $amount,
        (float) $fee,
        $gatewayModuleName,
    );

    logTransaction($gatewayParams['name'], [
        'event'      => 'return',
        'payment_id' => $paymentId,
        'trx_id'     => $trxId,
        'amount'     => $amount,
        'fee'        => $fee,
        'invoice_id' => $invoiceId,
    ], 'Return - Payment Applied');

    header('Location: ' . $redirectTo);
    exit;
}

// ===========================================================================
// ROUTE 2: POST - Server-to-server webhook from OwnPay
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

header('Content-Type: application/json');

$rawBody = (string) file_get_contents('php://input');

if ($rawBody === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

// ---------------------------------------------------------------------------
// Webhook signature verification
// ---------------------------------------------------------------------------
$webhookSecret = (string) ($gatewayParams['webhook_secret'] ?? '');

if ($webhookSecret !== '') {
    $signature = ownpay_cb_extractSignature();

    if ($signature === '') {
        logTransaction($gatewayParams['name'], [
            'event'   => 'webhook',
            'warning' => 'Webhook received without a signature header - rejected.',
        ], 'Webhook - Missing Signature');
        http_response_code(401);
        echo json_encode(['error' => 'Webhook signature header is missing']);
        exit;
    }

    // Support sha256=<hex> prefix (standard practice).
    if (str_starts_with($signature, 'sha256=')) {
        $signature = substr($signature, 7);
    }

    $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

    if (!hash_equals($expected, $signature)) {
        logTransaction($gatewayParams['name'], [
            'event'   => 'webhook',
            'warning' => 'HMAC signature mismatch - webhook rejected.',
        ], 'Webhook - Signature Mismatch');
        http_response_code(403);
        echo json_encode(['error' => 'Signature verification failed']);
        exit;
    }
} else {
    // Always log this warning so admins can diagnose a missing secret in any environment.
    logTransaction($gatewayParams['name'], [
        'event'   => 'webhook',
        'warning' => 'No webhook_secret is configured. Webhook rejected. '
            . 'Configure a Webhook Secret in the OwnPay gateway settings to enable HMAC verification.',
    ], 'Webhook - No Secret Configured');
    http_response_code(403);
    echo json_encode(['error' => 'Webhook secret not configured on this integration']);
    exit;
}

// ---------------------------------------------------------------------------
// Parse and route webhook payload
// ---------------------------------------------------------------------------
$webhookData = json_decode($rawBody, true);

if (!is_array($webhookData)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$event           = (string) ($webhookData['event'] ?? '');
$data            = $webhookData['data'] ?? $webhookData;

// API spec: TransactionResponse uses 'trx_id' (e.g. 'OP-481029304').
// 'transaction_id' is a RefundResponse field pointing to a parent record integer,
// and does NOT appear in payment webhook payloads.
$transactionId   = (string) ($data['trx_id'] ?? $data['id'] ?? '');
$gatewayTrxId    = (string) ($data['gateway_trx_id'] ?? '');
$paymentAmount   = (string) ($data['amount'] ?? '');
$paymentCurrency = (string) ($data['currency'] ?? '');
$status          = strtolower((string) ($data['status'] ?? ''));
$paymentFee      = (string) ($data['fee'] ?? '0.00');

// --- Extract invoice ID (reference > metadata fallback chain) ---
$invoiceId = null;

if (!empty($data['reference'])) {
    $ref       = $data['reference'];
    $invoiceId = is_array($ref)
        ? ($ref['reference'] ?? $ref['invoice_id'] ?? null)
        : $ref;
}

if ($invoiceId === null && !empty($data['metadata'])) {
    $meta      = is_string($data['metadata'])
        ? (json_decode($data['metadata'], true) ?: [])
        : $data['metadata'];
    $invoiceId = $meta['whmcs_invoice_id'] ?? $meta['invoice_id'] ?? $meta['reference'] ?? null;
}

if ($invoiceId === null && !empty($webhookData['metadata'])) {
    $meta      = is_string($webhookData['metadata'])
        ? (json_decode($webhookData['metadata'], true) ?: [])
        : $webhookData['metadata'];
    $invoiceId = $meta['whmcs_invoice_id'] ?? $meta['invoice_id'] ?? null;
}

// --- Classify status ---
$isSuccess = $status === 'completed';   // Only 'completed' per API spec
$isFailed  = in_array($status, ['failed', 'cancelled', 'expired', 'declined', 'rejected'], true);

$transactionStatus = match (true) {
    $isSuccess => 'Webhook - Success',
    $isFailed  => 'Webhook - ' . ucfirst($status),
    default    => 'Webhook - Pending (' . ucfirst($status) . ')',
};

// --- Validate invoice ID ---
if ($invoiceId === null || $invoiceId === '') {
    logTransaction($gatewayParams['name'], [
        'event'   => 'webhook',
        'payload' => $webhookData,
        'error'   => 'Invoice ID not found in webhook payload',
    ], 'Webhook - Missing Invoice ID');
    http_response_code(400);
    echo json_encode(['error' => 'Invoice ID not found in webhook payload']);
    exit;
}

$invoiceId = (int) $invoiceId;

if ($invoiceId <= 0) {
    logTransaction($gatewayParams['name'], [
        'event'   => 'webhook',
        'payload' => $webhookData,
        'error'   => 'Invalid invoice ID: ' . $invoiceId,
    ], 'Webhook - Invalid Invoice ID');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Invoice ID']);
    exit;
}

// --- Deduplication key ---
// Prefer the OwnPay trx_id (OP-XXXXXXXX). Fall back to gateway_trx_id.
// Last resort: a deterministic hash of the raw body so we never double-process.
$uniqueTransactionId = $transactionId !== '' ? $transactionId : $gatewayTrxId;
if ($uniqueTransactionId === '') {
    $uniqueTransactionId = 'OP-WH-' . md5($rawBody);
}

// checkCbInvoiceID: validates the invoice and exits (HTTP 200) if invalid.
// checkCbTransID:   exits (HTTP 200) with a success message if already processed.
// Both are appropriate here because this is a server-to-server POST endpoint.
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
checkCbTransID($uniqueTransactionId);

// --- Log all incoming verified webhooks ---
logTransaction($gatewayParams['name'], [
    'event'          => $event,
    'transaction_id' => $transactionId,
    'gateway_trx_id' => $gatewayTrxId,
    'invoice_id'     => $invoiceId,
    'amount'         => $paymentAmount,
    'currency'       => $paymentCurrency,
    'status'         => $status,
    'fee'            => $paymentFee,
    'customer'       => $data['customer'] ?? [],
], $transactionStatus);

// --- Apply payment on success ---
if ($isSuccess) {
    addInvoicePayment(
        $invoiceId,
        $uniqueTransactionId,
        (float) $paymentAmount,
        (float) $paymentFee,
        $gatewayParams['paymentmethod'] ?? $gatewayModuleName,
    );

    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Payment applied successfully']);
    exit;
}

// Non-success webhooks (failed, pending, etc.) - acknowledge receipt with no action.
http_response_code(200);
echo json_encode([
    'status'  => 'ok',
    'message' => $isFailed ? 'Payment failure acknowledged' : 'Webhook received',
]);
exit;

// ===========================================================================
// File-scoped helpers
// (PHP hoists function definitions so these are available throughout the file)
// ===========================================================================

/**
 * Simple GET request to the OwnPay API.
 *
 * @return array{body: string, http_code: int, error: string, errno: int}
 */
function ownpay_cb_apiGet(string $url, #[\SensitiveParameter] string $apiKey): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
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
function ownpay_cb_parseJson(string $body): array
{
    if ($body === '' || !json_validate($body)) {
        return [];
    }

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Extract the OwnPay webhook HMAC signature from request headers.
 *
 * Checks common header variants (both apache_request_headers and $_SERVER).
 */
function ownpay_cb_extractSignature(): string
{
    $headerNames = [
        'X-OP-Signature',
        'X-Signature',
        'X-OwnPay-Signature',
        'X-Webhook-Signature',
    ];

    foreach ($headerNames as $name) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
    }

    if (function_exists('apache_request_headers')) {
        $apacheHeaders = apache_request_headers();
        $lowerNames    = array_map('strtolower', $headerNames);
        foreach ($apacheHeaders as $headerKey => $headerValue) {
            if (in_array(strtolower($headerKey), $lowerNames, true)) {
                return (string) $headerValue;
            }
        }
    }

    return '';
}
