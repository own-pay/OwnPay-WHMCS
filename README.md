# OwnPay Payment Gateway Module for WHMCS

A WHMCS payment gateway module that integrates with the [OwnPay](https://ownpay.org) payment platform. Customers are redirected to OwnPay's checkout to complete payment, then returned to WHMCS upon completion.

## Features

- **Hosted Checkout** - Customers are redirected to OwnPay's secure checkout page for payment
- **Webhook Notifications** - Asynchronous payment confirmation via HMAC-SHA256 signed webhooks
- **Refund Support** - Process full or partial refunds directly from the WHMCS admin
- **Transaction Information** - View detailed transaction data in the WHMCS admin area
- **Custom Logo** - Display a custom logo on the OwnPay checkout page
- **Test Mode** - Enable detailed API logging for debugging and troubleshooting
- **Secure** - HMAC-SHA256 webhook verification, HTTPS enforcement, output escaping

## Requirements

- **WHMCS** 8.0 or later (fully compatible with WHMCS 9.0+)
- **PHP** 8.1 or later (WHMCS 9.0 requires PHP 8.2+)
- **cURL** PHP extension enabled
- **OwnPay** instance with API access enabled

## Installation

### Manual Installation

1. Download or clone this repository
2. Upload `modules/gateways/ownpay.php` to your WHMCS installation at `<whmcs>/modules/gateways/ownpay.php`
3. Upload `modules/gateways/callback/ownpay.php` to `<whmcs>/modules/gateways/callback/ownpay.php`
4. Upload the `modules/gateways/ownpay/` directory to `<whmcs>/modules/gateways/ownpay/`

### File Structure

After installation, your WHMCS directory structure should look like:

```
<whmcs>/
├── modules/
│   └── gateways/
│       ├── ownpay.php                          # Main gateway module
│       ├── ownpay/
│       │   ├── logo.svg                        # Default gateway logo
│       │   └── lang/
│       │       └── english.php                 # English language strings
│       └── callback/
│           └── ownpay.php                      # Webhook callback handler
```

## Configuration

### WHMCS Setup

1. Navigate to **Setup > Payments > Payment Gateways**
2. Click **Activate** next to "OwnPay Payment Gateway"
3. Configure the following settings:

| Setting | Description |
|---------|-------------|
| **OwnPay Base URL** | Your OwnPay instance URL (e.g. `https://pay.example.com`). No trailing slash. |
| **API Key** | Your OwnPay API key (starts with `op_`). Found in OwnPay Admin > Your Brand > Developer Hub > API Keys. |
| **Webhook Secret** | HMAC-SHA256 secret for webhook signature verification. The Webhook URL is displayed in this field's description - copy it into your OwnPay webhook settings. |
| **Gateway Logo URL** | Optional: URL to a custom logo image displayed on the checkout page. |
| **Test Mode** | Enable to log API requests and responses for debugging. |

### OwnPay Webhook Setup

1. In WHMCS, navigate to **Setup > Payments > Payment Gateways** and select OwnPay
2. The **Webhook Secret** field description shows your Webhook URL in this format:

   ```
   https://your-whmcs-domain.com/modules/gateways/callback/ownpay.php
   ```

3. Copy this URL and add it in your OwnPay admin panel under **Developer Hub > Webhooks > Add Webhook endpoint**
4. Set the webhook secret (must match the value configured in WHMCS)
5. Subscribe to the following events:
   - `payment.completed`
   - `payment.failed`
   - `payment.refunded`

## How It Works

### Payment Flow

```
Customer clicks "Pay Now" on WHMCS invoice
        │
        ▼
WHMCS calls ownpay_link() function
        │
        ▼
Module creates payment intent via OwnPay API
(POST /api/v1/payments)
        │
        ▼
Customer is redirected to OwnPay hosted checkout
        │
        ▼
Customer completes payment on OwnPay
        │
        ├─── Webhook sent to WHMCS callback ──► ownpay/callback/ownpay.php
        │    verifies HMAC signature            validates invoice
        │    applies payment to invoice         logs transaction
        │
        └─── Customer redirected back to WHMCS ──► Invoice marked as paid
```

### Webhook Security

All webhooks from OwnPay are verified using HMAC-SHA256 signatures:

- Signature is sent in the `X-OP-Signature`, `X-Signature`, or `X-OwnPay-Signature` header
- Computed as: `HMAC-SHA256(raw_request_body, webhook_secret)`
- Verified using `hash_equals()` for timing-safe comparison
- Requests with invalid signatures are rejected with HTTP 403

### Refund Processing

Refunds are processed via the OwnPay API:

- Full refunds: Click "Refund" on the transaction without specifying an amount
- Partial refunds: Specify a partial amount when initiating the refund
- Refund status is tracked and displayed in the WHMCS admin

## API Endpoints Used

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/payments` | POST | Create payment intent |
| `/api/v1/payments/{id}` | GET | Check payment status |
| `/api/v1/refunds` | POST | Process refund |
| `/api/v1/transactions/{id}` | GET | Retrieve transaction details |
| `/api/v1/health` | GET | Connectivity check |

## Troubleshooting

### Enable Test Mode

Enable **Test Mode** in the gateway configuration to log all API requests and responses to the WHMCS Gateway Log (**Utilities > Logs > Gateway Log**).

### Common Issues

| Issue | Solution |
|-------|----------|
| "OwnPay gateway is not configured" | Set the Base URL and API Key in gateway configuration |
| "Connection to OwnPay failed" | Verify the Base URL is correct and accessible from your server |
| "Signature verification failed" | Ensure the Webhook Secret matches between WHMCS and OwnPay |
| "Invoice ID not found in webhook payload" | Verify the webhook is sending the `reference` field with the invoice ID |
| Payment not applied after webhook | Check the Gateway Log for errors; ensure the callback file is uploaded correctly |

### Debug Checklist

1. Verify cURL extension is enabled: `php -m | grep curl`
2. Test API connectivity: `curl -H "Authorization: Bearer YOUR_KEY" https://your-ownpay.com/api/v1/health`
3. Check WHMCS Gateway Log for detailed error messages
4. Verify webhook URL is accessible from the OwnPay server
5. Ensure webhook secret matches between WHMCS and OwnPay

## Changelog

### 1.0.0 - 2026-07-07

- Initial release
- Third-party gateway integration with OwnPay checkout
- Webhook callback handler with HMAC-SHA256 signature verification
- Refund processing support
- Transaction information display in admin area
- Test mode with detailed API logging
- English language support

## License

This module is released under the [MIT License](LICENSE).

## Support

- [OwnPay Documentation](https://learn.ownpay.org)
- [WHMCS Payment Gateway Documentation](https://developers.whmcs.com/payment-gateways/)
- [Report Issues](https://github.com/own-pay/ownpay/issues)
