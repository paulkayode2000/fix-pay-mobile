<?php

namespace App\Services\Gateway;

use RuntimeException;

/**
 * Thrown when the Payfixy gateway answers with a non-success HTTP status.
 *
 * Carries the HTTP status and decoded body so callers can distinguish a
 * definitive rejection (4xx — the provider was never reached) from an
 * ambiguous provider-leg failure (5xx / empty body — money may have moved).
 */
class GatewayRequestException extends RuntimeException
{
    public function __construct(
        string $path,
        private readonly int $status,
        private readonly array|string|null $body,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): array|string|null
    {
        return $this->body;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /** True when the gateway gave no machine-readable error body at all. */
    public function hasEmptyBody(): bool
    {
        return $this->body === null || $this->body === [] || $this->body === '';
    }
}
