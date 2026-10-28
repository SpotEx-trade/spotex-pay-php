# SpotEx Pay for PHP

[![tests](https://github.com/SpotEx-trade/spotex-pay-php/actions/workflows/tests.yml/badge.svg)](https://github.com/SpotEx-trade/spotex-pay-php/actions/workflows/tests.yml)

Accept crypto on your website with [SpotEx Pay](https://spotex.trade/merchants): a hosted checkout or your own, signed webhooks, payouts, and a test mode to build against. PHP 7.4+, with the curl and json extensions; no other dependencies.

- **0.5% per payment**, no setup or monthly fee
- Buyers pay from any wallet or their SpotEx balance
- Amounts are decimal strings: add them with bcmath, never floats

API reference: **https://spotex.trade/docs/merchant**

## Install

With Composer, from GitHub:

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/SpotEx-trade/spotex-pay-php" }],
    "require": { "spotex/pay": "^0.1" }
}
```

```sh
composer update spotex/pay
```

No Composer? Copy `src/` into your project and `require` its four files.

## Get a key

Apply as a merchant at [spotex.trade/merchant](https://spotex.trade/merchant). Once approved, make keys under **Merchant Center → Developers**:

- `sk_test_…`: **test mode**. Checkouts and sessions work exactly as live and webhooks are sent, but no money moves. Start here.
- `sk_live_…`: real payments. Payout permissions are separate, and need an IP allowlist.

Keep keys on your server, in an environment variable or config outside your web root.

## Take a payment on SpotEx's page

```php
use SpotEx\Pay\SpotExPay;

$spotex = new SpotExPay(getenv('SPOTEX_KEY'));

$session = $spotex->createCheckoutSession([
    'price' => '25.00',
    'currency' => 'EUR', // or USD, GBP, JPY... any of https://spotex.trade/docs/merchant#currencies
    'item' => 'Gift box',
    'order_id' => '1043',
    'return_url' => 'https://yourshop.com/thanks', // must be on your merchant website
], 'order-1043'); // idempotency key: same key, same session

header('Location: ' . $session['url'], true, 303);
```

Your price becomes dollars at the exchange rate when the buyer opens the checkout. `'amount_usd' => '25.00'` in place of `price` and `currency` still works, for a price in dollars.

## Or in your own design

```php
$checkout = $spotex->createCheckout(['price' => '25.00', 'currency' => 'EUR', 'coin' => 'DOGE', 'item' => 'Gift box', 'order_id' => '1043']);
// show $checkout['address'] and $checkout['amount'], then wait for the webhook
// or poll $spotex->retrieveCheckout($checkout['id'])
```

`$spotex->listCoins()` says which coins you accept and which can be sent from any wallet (`'onchain' => true`).

## Webhooks

Check the signature against the **raw** body before trusting anything in it:

```php
use SpotEx\Pay\SignatureVerificationException;
use SpotEx\Pay\Webhook;

$raw = file_get_contents('php://input');
try {
    $event = Webhook::constructEvent($raw, $_SERVER['HTTP_SPOTEX_SIGNATURE'] ?? null, getenv('SPOTEX_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'checkout.paid' && !empty($event['livemode'])) {
    mark_order_paid($event['data']['checkout']['order_id']); // once per $event['id']
}
```

Events older than five minutes are refused. Events: `checkout.paid`, `checkout.partial`, `checkout.extra`, `checkout.refunded`, `payout.withdrawal.*`, `payout.transfer.*`, `test`.

## Test mode

```php
$checkout = $spotex->createCheckout(['amount_usd' => '10.00', 'coin' => 'DOGE', 'item' => 'Test']);
$spotex->simulateCheckout($checkout['id'], 'partial'); // or 'paid', 'expired'
```

Or open its `hosted_url` and press a button. Test objects and their webhooks carry `livemode: false`.

## Payouts

From your merchant wallet, with a live key that has the permission. Always pass your own idempotency key, and retry with the same one:

```php
$spotex->createTransfer(['to' => 'alice', 'coin' => 'USDT', 'amount' => '15.00', 'note' => 'Refund 1043'], 'refund-1043');
$spotex->createWithdrawal(['coin' => 'DOGE', 'amount' => '500', 'address' => 'D...'], 'payout-77');
$spotex->retrieveBalance();
```

## Errors

Anything other than 2xx throws `SpotExPayException`: `getStatus()`, `getErrorCode()` (`invalid_request`, `insufficient_funds`, `permission_denied`, `test_mode`, `rate_limited`, …) and `getMessage()`. A network failure or timeout is `network_error`: retry with the same idempotency key.

## Examples

- [`examples/checkout.php`](examples/checkout.php): send a buyer to a checkout session
- [`examples/webhook.php`](examples/webhook.php): a webhook endpoint
- [`examples/test-mode.php`](examples/test-mode.php): the whole flow in test mode, from the command line

## Development

```sh
php tests/run.php
```

## License

MIT
