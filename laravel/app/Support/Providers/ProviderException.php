<?php

namespace App\Support\Providers;

use RuntimeException;

/**
 * A provider call failed (CONTRACT-05). `$retryable` is the single fact the
 * fallback policy keys on: true for the transient failures the backlog names
 * (429 / 5xx / timeout) — try the next provider — false for anything else (bad
 * request, auth) — surface it, don't mask a real bug behind a fallback.
 *
 * The Python twin is app/providers/base.py::ProviderError; keep the semantics of
 * `retryable` identical so the two engines fall back on the same conditions.
 */
final class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly string $provider,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
