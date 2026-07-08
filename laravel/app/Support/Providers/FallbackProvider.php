<?php

namespace App\Support\Providers;

use Generator;

/**
 * Tries providers in order, advancing to the next only on a *retryable*
 * ProviderException. Same policy as python/app/providers/base.py::FallbackProvider
 * — that identical policy is the fairness property CONTRACT-05 pins.
 *
 * Streaming caveat: chat can only fall back *before the first token* — once tokens
 * are on the wire we can't un-send them. That matches reality (429/5xx come back
 * on the initial request, not mid-stream): we start the upstream generator and
 * pull its first item under the try, so a retryable failure there routes on
 * transparently; a failure after tokens have flowed propagates instead of
 * double-answering.
 */
final class FallbackProvider
{
    /**
     * @param  list<Provider>  $chatChain
     * @param  list<Provider>  $embedChain
     */
    public function __construct(
        public readonly array $chatChain,
        public readonly array $embedChain,
    ) {}

    /** @return Generator<int, string> */
    public function chat(string $prompt): Generator
    {
        $lastError = null;

        foreach ($this->chatChain as $provider) {
            $stream = $provider->chat($prompt);

            try {
                // rewind() runs the provider generator up to its first yield —
                // where the HTTP request happens and a retryable status throws.
                $stream->rewind();
            } catch (ProviderException $error) {
                if ($error->retryable) {
                    $lastError = $error;

                    continue;
                }
                throw $error;
            }

            // First chunk landed (or the stream is empty): this provider owns the
            // rest. Pull manually — a second rewind() on a started generator throws.
            while ($stream->valid()) {
                yield $stream->current();
                $stream->next();
            }

            return;
        }

        throw $lastError ?? new ProviderException(
            'no chat provider configured', retryable: false, provider: 'fallback',
        );
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        $lastError = null;

        foreach ($this->embedChain as $provider) {
            try {
                return $provider->embed($texts);
            } catch (ProviderException $error) {
                if ($error->retryable) {
                    $lastError = $error;

                    continue;
                }
                throw $error;
            }
        }

        throw $lastError ?? new ProviderException(
            'no embedding provider configured', retryable: false, provider: 'fallback',
        );
    }
}
