<?php
// Plain tests, no PHPUnit needed: php tests/run.php

// Any notice, warning or deprecation fails the run, so a new PHP version that
// objects to something here is caught.
error_reporting(E_ALL);
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/../src/SpotExPayException.php';
require __DIR__ . '/../src/SignatureVerificationException.php';
require __DIR__ . '/../src/Webhook.php';
require __DIR__ . '/../src/SpotExPay.php';

use SpotEx\Pay\SignatureVerificationException;
use SpotEx\Pay\SpotExPay;
use SpotEx\Pay\SpotExPayException;
use SpotEx\Pay\Webhook;

$passed = 0;
$failed = 0;
function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  PASS  $name\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  $name\n        " . get_class($e) . ': ' . $e->getMessage() . "\n";
        $failed++;
    }
}
function same($a, $b, string $what = ''): void
{
    if ($a !== $b) {
        throw new \Exception(($what ? "$what: " : '') . var_export($a, true) . ' !== ' . var_export($b, true));
    }
}
function throws(callable $fn, string $class, string $match = ''): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            throw new \Exception('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }
        if ($match !== '' && strpos($e->getMessage(), $match) === false) {
            throw new \Exception("message '" . $e->getMessage() . "' lacks '$match'");
        }
        return;
    }
    throw new \Exception("expected $class, nothing thrown");
}

/** A transport that records requests and answers as told. */
function recorder(array &$seen, int $status = 200, array $answer = []): callable
{
    return function (string $method, string $url, array $headers, ?string $body) use (&$seen, $status, $answer) {
        $seen[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];
        return [$status, json_encode($answer)];
    };
}
function header_of(array $request, string $name): ?string
{
    foreach ($request['headers'] as $h) {
        if (stripos($h, $name . ':') === 0) {
            return trim(substr($h, strlen($name) + 1));
        }
    }
    return null;
}

check('refuses anything but a secret key', function () {
    throws(function () {
        new SpotExPay('pk_nope');
    }, \InvalidArgumentException::class);
    same((new SpotExPay('sk_test_abc'))->isTestMode(), true);
    same((new SpotExPay('sk_live_abc'))->isTestMode(), false);
});

check('creates a checkout session: bearer key, JSON body, the idempotency key given', function () {
    $seen = [];
    $spotex = new SpotExPay('sk_test_abc', ['base_url' => 'https://x.test/api/merchant/v1', 'transport' => recorder($seen, 201, ['id' => 'cs1', 'url' => 'https://spotex.trade/m/cs1'])]);
    $s = $spotex->createCheckoutSession(['amount_usd' => '25.00', 'item' => 'Gift box', 'order_id' => '1043', 'return_url' => null], 'order-1043');
    same($s['url'], 'https://spotex.trade/m/cs1');
    same($seen[0]['method'], 'POST');
    same($seen[0]['url'], 'https://x.test/api/merchant/v1/checkout-sessions');
    same(header_of($seen[0], 'Authorization'), 'Bearer sk_test_abc');
    same(header_of($seen[0], 'Idempotency-Key'), 'order-1043');
    same($seen[0]['body'], ['amount_usd' => '25.00', 'item' => 'Gift box', 'order_id' => '1043'], 'nulls dropped');
});

check('a price in another currency goes as price and currency', function () {
    $seen = [];
    $spotex = new SpotExPay('sk_test_abc', ['transport' => recorder($seen, 201, ['id' => 'cs2', 'price' => '25.00', 'currency' => 'EUR', 'amount_usd' => null])]);
    $s = $spotex->createCheckoutSession(['price' => '25.00', 'currency' => 'EUR', 'item' => 'Gift box']);
    same($s['currency'], 'EUR');
    same($seen[0]['body'], ['price' => '25.00', 'currency' => 'EUR', 'item' => 'Gift box']);
});

check('makes an idempotency key for a POST when none is given, and none for a GET', function () {
    $seen = [];
    $spotex = new SpotExPay('sk_live_abc', ['base_url' => 'https://x.test/v1', 'transport' => recorder($seen, 200, ['data' => []])]);
    $spotex->createCheckout(['amount_usd' => '10', 'coin' => 'DOGE', 'item' => 'X']);
    $spotex->listCheckouts(['status' => 'paid', 'limit' => 5, 'before' => null]);
    same(preg_match('/^php-[0-9a-f]{32}$/', header_of($seen[0], 'Idempotency-Key')), 1);
    same(header_of($seen[1], 'Idempotency-Key'), null);
    same($seen[1]['url'], 'https://x.test/v1/checkouts?status=paid&limit=5');
});

check('each endpoint goes where the API reference says', function () {
    $seen = [];
    $s = new SpotExPay('sk_live_abc', ['base_url' => 'https://x.test/v1', 'transport' => recorder($seen)]);
    $s->retrieveCheckoutSession('cs/1');
    $s->retrieveCheckout('c1');
    $s->simulateCheckout('c1', 'partial');
    $s->listCoins();
    $s->retrieveBalance();
    $s->createTransfer(['to' => 'alice', 'coin' => 'USDT', 'amount' => '5']);
    $s->retrieveTransfer('t1');
    $s->createWithdrawal(['coin' => 'DOGE', 'amount' => '100', 'address' => 'D8aM']);
    $s->retrieveWithdrawal('w1');
    same(array_map(function ($r) {
        return $r['method'] . ' ' . str_replace('https://x.test/v1', '', $r['url']);
    }, $seen), [
        'GET /checkout-sessions/cs%2F1',
        'GET /checkouts/c1',
        'POST /checkouts/c1/simulate',
        'GET /coins',
        'GET /balance',
        'POST /payouts/transfers',
        'GET /payouts/transfers/t1',
        'POST /payouts/withdrawals',
        'GET /payouts/withdrawals/w1',
    ]);
    same($seen[2]['body'], ['outcome' => 'partial']);
});

check('an API error becomes a SpotExPayException with its status and code', function () {
    $seen = [];
    $s = new SpotExPay('sk_test_abc', ['transport' => recorder($seen, 403, ['error' => ['code' => 'test_mode', 'message' => "This can't be done with a test key."]])]);
    try {
        $s->retrieveBalance();
        throw new \Exception('nothing thrown');
    } catch (SpotExPayException $e) {
        same([$e->getStatus(), $e->getErrorCode()], [403, 'test_mode']);
    }
});

check('a server that cannot be reached is a network_error (curl)', function () {
    if (!function_exists('curl_init')) {
        echo "        (skipped: no curl extension)\n";
        return;
    }
    $s = new SpotExPay('sk_live_abc', ['base_url' => 'http://127.0.0.1:1/api', 'timeout' => 2]);
    try {
        $s->listCoins();
        throw new \Exception('nothing thrown');
    } catch (SpotExPayException $e) {
        same($e->getErrorCode(), 'network_error');
    }
});

// The vector every SpotEx Pay SDK is tested against, signed by the server's own code.
const SECRET = 'whsec_test_123';
const BODY = '{"id":"evt_1","type":"checkout.paid","created":1793071234,"livemode":true,"data":{"checkout":{"id":"cm1","order_id":"1043","status":"paid"}}}';
const HEADER = 't=1793071234,v1=18217f112e2f8d2f3115929e1be70e8f16f429eb28d429c6221caa60f1476863';

check('verifies the shared webhook vector', function () {
    $event = Webhook::constructEvent(BODY, HEADER, SECRET, 0);
    same($event['data']['checkout']['order_id'], '1043');
    same(Webhook::sign(BODY, SECRET, 1793071234), HEADER);
});

check('refuses a webhook that was changed, signed with another secret, too old or malformed', function () {
    throws(function () {
        Webhook::constructEvent(str_replace('1043', '9999', BODY), HEADER, SECRET, 0);
    }, SignatureVerificationException::class);
    throws(function () {
        Webhook::constructEvent(BODY, HEADER, 'whsec_other', 0);
    }, SignatureVerificationException::class);
    throws(function () {
        Webhook::constructEvent(BODY, HEADER, SECRET);
    }, SignatureVerificationException::class, 'too old');
    throws(function () {
        Webhook::constructEvent(BODY, 'nonsense', SECRET, 0);
    }, SignatureVerificationException::class, 'form');
    throws(function () {
        Webhook::constructEvent(BODY, null, SECRET, 0);
    }, SignatureVerificationException::class, 'No SpotEx-Signature');
    same(Webhook::verify(BODY, Webhook::sign(BODY, SECRET), SECRET), true);
});

check('refuses to verify or sign without a secret: getenv() finding none must not let anyone in', function () {
    // Signed with an empty key, as an attacker could when the secret is empty.
    $forged = 't=1793071234,v1=' . hash_hmac('sha256', '1793071234.' . BODY, '');
    throws(function () use ($forged) {
        Webhook::constructEvent(BODY, $forged, '', 0);
    }, \InvalidArgumentException::class, 'no webhook signing secret');
    throws(function () use ($forged) {
        Webhook::verify(BODY, $forged, (string) getenv('SPOTEX_NO_SUCH_VARIABLE'), 0);
    }, \InvalidArgumentException::class);
    throws(function () {
        Webhook::sign(BODY, '');
    }, \InvalidArgumentException::class);
});

check('a body that cannot be JSON is refused before anything is sent', function () {
    $seen = [];
    $s = new SpotExPay('sk_test_abc', ['transport' => recorder($seen)]);
    throws(function () use ($s) {
        $s->createCheckout(['amount_usd' => '1', 'coin' => 'DOGE', 'item' => "\xB1\x31"]);
    }, \InvalidArgumentException::class, 'JSON');
    same($seen, []);
});

echo $failed === 0 ? "\n$passed passed, 0 failed\n" : "\n$passed passed, $failed FAILED\n";
exit($failed === 0 ? 0 : 1);
