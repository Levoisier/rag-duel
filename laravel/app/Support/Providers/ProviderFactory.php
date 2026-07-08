<?php

namespace App\Support\Providers;

use App\Support\Contract;
use RuntimeException;

/**
 * Builds the fallback provider from the shared contract order (CONTRACT-05).
 *
 * The chain order is read from AI_PROVIDER_PRIMARY / AI_PROVIDER_FALLBACK — the
 * same two keys the Python factory reads — so neither engine hardcodes "gemini
 * then groq". The embed chain is the same chain filtered to embedding-capable
 * providers (Groq has no embeddings API).
 */
final class ProviderFactory
{
    public static function make(): FallbackProvider
    {
        $chain = [
            self::instantiate(Contract::get('AI_PROVIDER_PRIMARY')),
            self::instantiate(Contract::get('AI_PROVIDER_FALLBACK')),
        ];

        $embedChain = array_values(array_filter(
            $chain,
            static fn (Provider $provider): bool => $provider->supportsEmbed(),
        ));

        return new FallbackProvider($chain, $embedChain);
    }

    /**
     * The provider the engine actually uses: the fallback seam behind the
     * app-side throttle (CONTRACT-06), both configured from the contract.
     */
    public static function throttled(): ThrottledProvider
    {
        return new ThrottledProvider(self::make(), Throttle::fromContract());
    }

    private static function instantiate(string $name): Provider
    {
        return match ($name) {
            'gemini' => new GeminiProvider,
            'groq' => new GroqProvider,
            default => throw new RuntimeException("Unknown provider [{$name}] in contract — add it to the factory."),
        };
    }
}
