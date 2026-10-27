<?php
// Try the whole flow in test mode, from the command line:
//   SPOTEX_KEY=sk_test_... php test-mode.php
require __DIR__ . '/vendor/autoload.php';

use SpotEx\Pay\SpotExPay;

$spotex = new SpotExPay(getenv('SPOTEX_KEY'));
if (!$spotex->isTestMode()) {
    exit("Use a test key (sk_test_...) for this script.\n");
}

$coin = null;
foreach ($spotex->listCoins()['data'] as $c) {
    if ($c['onchain']) {
        $coin = $c['coin'];
        break;
    }
}
if ($coin === null) {
    exit("You accept no coin that can be sent from a wallet.\n");
}

$checkout = $spotex->createCheckout(['amount_usd' => '10.00', 'coin' => $coin, 'item' => 'Test order', 'order_id' => 'test-1']);
echo "Made {$checkout['id']}: send {$checkout['amount']} {$checkout['coin']} to {$checkout['address']} (a stand-in)\n";

$half = $spotex->simulateCheckout($checkout['id'], 'partial');
echo "After half: {$half['status']}, remaining {$half['remaining']}\n";

$paid = $spotex->simulateCheckout($checkout['id'], 'paid');
echo "After the rest: {$paid['status']} - your webhook got checkout.partial and checkout.paid, livemode false\n";
