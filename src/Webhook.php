<?php

namespace SpotEx\Pay;

/**
 * SpotEx signs every webhook: SpotEx-Signature: t=<unix>,v1=<hex HMAC-SHA256
 * of "<t>.<raw body>" with your webhook signing secret>.
 */
final class Webhook
{
    /**
     * Checks the signature against the raw request body (the exact bytes
     * received, before any JSON parsing) and returns the event as an array.
     * Throws SignatureVerificationException if it does not match, or if it
     * is older than $toleranceSeconds (a replay). Pass 0 to skip the age check.
     *
     *   $event = Webhook::constructEvent(file_get_contents('php://input'),
     *       $_SERVER['HTTP_SPOTEX_SIGNATURE'] ?? null, getenv('SPOTEX_WEBHOOK_SECRET'));
     */
    public static function constructEvent(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSeconds = 300): array
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new SignatureVerificationException('No SpotEx-Signature header.');
        }
        $parts = [];
        foreach (explode(',', $signatureHeader) as $piece) {
            $at = strpos($piece, '=');
            if ($at !== false && $at > 0) {
                $parts[trim(substr($piece, 0, $at))] = trim(substr($piece, $at + 1));
            }
        }
        $t = $parts['t'] ?? '';
        if (!ctype_digit($t) || ($parts['v1'] ?? '') === '') {
            throw new SignatureVerificationException('The SpotEx-Signature header is not in the form t=...,v1=....');
        }
        if ($toleranceSeconds > 0 && abs(time() - (int) $t) > $toleranceSeconds) {
            throw new SignatureVerificationException('The webhook is too old: refuse it as a possible replay.');
        }
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $parts['v1'])) {
            throw new SignatureVerificationException('The signature does not match: check the signing secret, and pass the raw body.');
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new SignatureVerificationException('The webhook body is not a JSON object.');
        }
        return $event;
    }

    /** True when the signature checks out; constructEvent says why when it does not. */
    public static function verify(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSeconds = 300): bool
    {
        try {
            self::constructEvent($rawBody, $signatureHeader, $secret, $toleranceSeconds);
            return true;
        } catch (SignatureVerificationException $e) {
            return false;
        }
    }

    /** Signs a body as SpotEx does: for your own tests of a webhook handler. */
    public static function sign(string $body, string $secret, ?int $unix = null): string
    {
        $t = $unix ?? time();
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
    }
}
