<?php

namespace App\Support\Providers;

use App\Support\Contract;
use Illuminate\Support\Facades\RateLimiter;

/**
 * App-side request throttle (CONTRACT-06) — a safety cap that keeps a runaway
 * loop from hammering the provider and blowing the free-tier quota.
 *
 * Both engines enforce the *same* ceiling from the shared contract; the mechanism
 * differs per runtime. A fresh php-fpm worker can't hold a counter between
 * requests, so this counts through Laravel's cache-backed RateLimiter (which
 * persists across requests) rather than an in-process window like the Python
 * side. What must match is the ceiling and the degrade-don't-hammer behaviour —
 * not the bookkeeping (cf. hrtime vs perf_counter for timings).
 *
 * `check()` refuses the call (throws ThrottleException) *before* the network is
 * touched once either horizon is met, so no path silently spends quota.
 */
final class Throttle
{
    private const MINUTE = 60;

    private const DAY = 86_400;

    public function __construct(
        private readonly int $maxRpm,
        private readonly int $maxRpd,
        private readonly string $key = 'provider',
    ) {}

    public static function fromContract(string $key = 'provider'): self
    {
        return new self(
            Contract::int('PROVIDER_MAX_RPM'),
            Contract::int('PROVIDER_MAX_RPD'),
            $key,
        );
    }

    public function check(): void
    {
        $minuteKey = "throttle:{$this->key}:rpm";
        $dayKey = "throttle:{$this->key}:rpd";

        if (RateLimiter::tooManyAttempts($minuteKey, $this->maxRpm)
            || RateLimiter::tooManyAttempts($dayKey, $this->maxRpd)) {
            throw new ThrottleException;
        }

        RateLimiter::hit($minuteKey, self::MINUTE);
        RateLimiter::hit($dayKey, self::DAY);
    }
}
