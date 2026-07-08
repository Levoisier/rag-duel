<?php

namespace App\Support\Providers;

use Generator;

/**
 * Wraps the fallback seam so every chat/embed consults the throttle first
 * (CONTRACT-06). The wrapped provider is untouched — throttling is a separate
 * concern layered on top. Twin of
 * python/app/providers/throttle.py::ThrottledProvider.
 */
final class ThrottledProvider
{
    public function __construct(
        private readonly FallbackProvider $inner,
        private readonly Throttle $throttle,
    ) {}

    /** @return Generator<int, string> */
    public function chat(string $prompt): Generator
    {
        $this->throttle->check();

        yield from $this->inner->chat($prompt);
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        $this->throttle->check();

        return $this->inner->embed($texts);
    }
}
