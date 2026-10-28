<?php

namespace SpotEx\Pay;

/**
 * The SpotEx Pay merchant API. Resources come back as arrays, with amounts as
 * decimal strings: add them with bcmath, never floats.
 *
 *   $spotex = new \SpotEx\Pay\SpotExPay(getenv('SPOTEX_KEY'));
 *   $session = $spotex->createCheckoutSession(['price' => '25.00', 'currency' => 'EUR', 'item' => 'Gift box'], 'order-1043');
 *   header('Location: ' . $session['url']);
 *
 * API reference: https://spotex.trade/docs/merchant
 */
class SpotExPay
{
    public const VERSION = '0.2.0';
    public const DEFAULT_BASE_URL = 'https://spotex.trade/api/merchant/v1';

    /** @var string */
    private $key;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeout;
    /** @var callable */
    private $transport;

    /**
     * @param array $options base_url, timeout (seconds, 30), transport (for tests:
     *   function (string $method, string $url, array $headers, ?string $body): array [int $status, string $body])
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (strpos($apiKey, 'sk_live_') !== 0 && strpos($apiKey, 'sk_test_') !== 0) {
            throw new \InvalidArgumentException('SpotExPay: pass your secret key, sk_live_... or sk_test_....');
        }
        $this->key = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (int) ($options['timeout'] ?? 30);
        $this->transport = $options['transport'] ?? [$this, 'curlTransport'];
    }

    /** A test key (sk_test_): test checkouts and sessions only, never money. */
    public function isTestMode(): bool
    {
        return strpos($this->key, 'sk_test_') === 0;
    }

    /* --------------------------------------------------------- sessions */

    /**
     * One order paid on SpotEx's own page: send the buyer to its 'url'.
     * Params: price with currency ("EUR", "JPY"...) or amount_usd, item, order_id?, return_url? (on your website), expires_in_minutes?
     */
    public function createCheckoutSession(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/checkout-sessions', $params, $idempotencyKey);
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->request('GET', '/checkout-sessions/' . rawurlencode($id));
    }

    /* -------------------------------------------------------- checkouts */

    /** An on-chain checkout to show in your own design. Params: price with currency or amount_usd, coin, item, order_id? */
    public function createCheckout(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/checkouts', $params, $idempotencyKey);
    }

    public function retrieveCheckout(string $id): array
    {
        return $this->request('GET', '/checkouts/' . rawurlencode($id));
    }

    /** Query: status ('paid'|'pending'), limit (max 100), before (an id). */
    public function listCheckouts(array $query = []): array
    {
        $query = array_filter($query, function ($v) {
            return $v !== null;
        });
        return $this->request('GET', '/checkouts' . ($query ? '?' . http_build_query($query) : ''));
    }

    /** Test mode only: $outcome is 'paid', 'partial' or 'expired'. */
    public function simulateCheckout(string $id, string $outcome): array
    {
        return $this->request('POST', '/checkouts/' . rawurlencode($id) . '/simulate', ['outcome' => $outcome]);
    }

    /* ------------------------------------------------- coins and balance */

    /** The coins you accept, their USD rate, and which can be sent from any wallet ('onchain'). */
    public function listCoins(): array
    {
        return $this->request('GET', '/coins');
    }

    /** Live keys only. */
    public function retrieveBalance(): array
    {
        return $this->request('GET', '/balance');
    }

    /* ---------------------------------------------------------- payouts */

    /** Pay a SpotEx user from the merchant wallet. Params: to (username or email), coin, amount, note? Pass your own idempotency key. */
    public function createTransfer(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/payouts/transfers', $params, $idempotencyKey);
    }

    public function retrieveTransfer(string $id): array
    {
        return $this->request('GET', '/payouts/transfers/' . rawurlencode($id));
    }

    /** Send from the merchant wallet on-chain. Params: coin, amount, address, memo?, network? Pass your own idempotency key. */
    public function createWithdrawal(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/payouts/withdrawals', $params, $idempotencyKey);
    }

    public function retrieveWithdrawal(string $id): array
    {
        return $this->request('GET', '/payouts/withdrawals/' . rawurlencode($id));
    }

    /* ------------------------------------------------------------- http */

    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->key,
            'Accept: application/json',
            'User-Agent: spotex-pay-php/' . self::VERSION,
        ];
        $json = null;
        if ($body !== null) {
            $json = json_encode(array_filter($body, function ($v) {
                return $v !== null;
            }));
            if ($json === false) {
                throw new \InvalidArgumentException('SpotExPay: the request could not be sent as JSON: ' . json_last_error_msg() . '.');
            }
            $headers[] = 'Content-Type: application/json';
        }
        if ($method === 'POST') {
            $headers[] = 'Idempotency-Key: ' . ($idempotencyKey ?? ('php-' . bin2hex(random_bytes(16))));
        }
        [$status, $raw] = call_user_func($this->transport, $method, $this->baseUrl . $path, $headers, $json);
        $data = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            $error = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
            throw new SpotExPayException($status, (string) ($error['code'] ?? 'http_error'), (string) ($error['message'] ?? ('HTTP ' . $status)));
        }
        if (!is_array($data)) {
            throw new SpotExPayException($status, 'invalid_response', 'SpotEx Pay answered with something that is not JSON.');
        }
        return $data;
    }

    /** @internal The default transport, over curl. */
    public function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $reason = curl_error($ch);
            self::closeCurl($ch);
            throw new SpotExPayException(0, 'network_error', 'Could not reach SpotEx Pay: ' . $reason . '. Retry with the same idempotency key.');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        self::closeCurl($ch);
        return [$status, (string) $raw];
    }

    /** PHP 8 frees the handle by itself, and 8.5 deprecates curl_close(). */
    private static function closeCurl($ch): void
    {
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
    }
}
