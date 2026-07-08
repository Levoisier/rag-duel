<?php

namespace App\Support\Providers;

use RuntimeException;

/**
 * The app-side throttle tripped before a network call was made (CONTRACT-06).
 * The message is user-facing on purpose — it's what the graceful-degradation
 * frame shows. Twin of python/app/providers/throttle.py::ThrottleError.
 */
final class ThrottleException extends RuntimeException
{
    public function __construct(string $message = 'rate limit hit, try again later')
    {
        parent::__construct($message);
    }
}
