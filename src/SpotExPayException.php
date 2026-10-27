<?php

namespace SpotEx\Pay;

/**
 * An API answer other than 2xx: getErrorCode() to branch on, getMessage() to log.
 * A network failure or timeout has status 0 and code "network_error": retry
 * with the same idempotency key.
 */
class SpotExPayException extends \RuntimeException
{
    /** @var int */
    private $status;
    /** @var string */
    private $errorCode;

    public function __construct(int $status, string $errorCode, string $message)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** "invalid_request", "insufficient_funds", "permission_denied", "test_mode", "rate_limited", "network_error", … */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
