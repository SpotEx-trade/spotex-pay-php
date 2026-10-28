<?php
// The buyer presses "Pay with crypto": make a checkout session for the order
// and send them to SpotEx Pay. Use a test key (sk_test_...) while you build.
require __DIR__ . '/vendor/autoload.php';

use SpotEx\Pay\SpotExPay;
use SpotEx\Pay\SpotExPayException;

$spotex = new SpotExPay(getenv('SPOTEX_KEY'));
$order = ['id' => '1043', 'total' => '25.00', 'currency' => 'USD', 'name' => 'Gift box']; // from your database

try {
    $session = $spotex->createCheckoutSession([
        'price' => $order['total'],
        'currency' => $order['currency'],
        'item' => $order['name'],
        'order_id' => $order['id'],
        'return_url' => 'https://yourshop.com/orders/' . $order['id'] . '/thanks',
    ], 'order-' . $order['id']); // same key, same session: retries are safe
    header('Location: ' . $session['url'], true, 303);
} catch (SpotExPayException $e) {
    http_response_code(502);
    echo 'Payment could not start: ' . htmlspecialchars($e->getMessage());
}
