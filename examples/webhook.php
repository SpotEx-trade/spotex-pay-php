<?php
// The webhook address you set in the Merchant Center. SpotEx retries anything
// but a 2xx answer for about a day, so answer quickly.
require __DIR__ . '/vendor/autoload.php';

use SpotEx\Pay\SignatureVerificationException;
use SpotEx\Pay\Webhook;

$raw = file_get_contents('php://input'); // the exact body: parse it only after the signature checks out
try {
    $event = Webhook::constructEvent($raw, $_SERVER['HTTP_SPOTEX_SIGNATURE'] ?? null, getenv('SPOTEX_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit('bad signature');
}

switch ($event['type']) {
    case 'checkout.paid':
        $checkout = $event['data']['checkout'];
        if (!empty($event['livemode'])) {
            // Mark the order paid once per event id: the same event can arrive more than once.
            mark_order_paid($checkout['order_id'], $event['id']);
        }
        break;
    case 'checkout.partial':
        // Part of it arrived. SpotEx holds it until the rest does, or you accept it in the Merchant Center.
        break;
}
http_response_code(200);

function mark_order_paid(string $orderId, string $eventId): void
{
    error_log("order $orderId paid (event $eventId)");
}
