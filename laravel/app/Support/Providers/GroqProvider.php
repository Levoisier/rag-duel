<?php

namespace App\Support\Providers;

use App\Support\Contract;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Groq — chat-only fallback (CONTRACT-05). Groq serves an OpenAI-compatible chat
 * API and *no* embeddings, so supportsEmbed() is false and the fallback wrapper
 * keeps it out of the embed chain. Temperature and max-tokens still come from the
 * shared contract so the degraded path stays as close to the primary as the model
 * allows; the Groq model id and key are env config — a fallback model isn't a
 * fairness parameter, and keys never live in the contract.
 *
 * Twin of python/app/providers/groq.py.
 */
final class GroqProvider implements Provider
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(private readonly ?string $apiKey = null) {}

    public function name(): string
    {
        return 'groq';
    }

    public function supportsEmbed(): bool
    {
        return false;
    }

    public function chat(string $prompt): Generator
    {
        $body = [
            'model' => config('services.groq.model'),
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => Contract::float('LLM_TEMPERATURE'),
            'max_tokens' => Contract::int('LLM_MAX_TOKENS'),
            'stream' => true,
        ];

        try {
            $response = Http::withToken($this->key())
                ->withOptions(['stream' => true])
                ->timeout(30)
                ->post(self::URL, $body);
        } catch (ConnectionException $error) {
            throw new ProviderException(
                "groq chat timeout: {$error->getMessage()}", retryable: true, provider: $this->name(),
            );
        }

        if ($response->status() !== 200) {
            throw new ProviderException(
                "groq chat HTTP {$response->status()}",
                retryable: $response->status() === 429 || $response->status() >= 500,
                provider: $this->name(),
                status: $response->status(),
            );
        }

        yield from GeminiProvider::streamSse($response->toPsrResponse()->getBody(), self::deltaExtractor());
    }

    public function embed(array $texts): array
    {
        // Never reached — the fallback wrapper excludes chat-only providers from
        // the embed chain — but fail loud if wiring ever regresses.
        throw new ProviderException(
            'groq has no embeddings API', retryable: false, provider: $this->name(),
        );
    }

    private function key(): string
    {
        $key = $this->apiKey ?? config('services.groq.key');

        if (empty($key)) {
            throw new ProviderException(
                'GROQ_API_KEY is not set', retryable: false, provider: $this->name(),
            );
        }

        return $key;
    }

    /** @return callable(string): (string|null) */
    private static function deltaExtractor(): callable
    {
        return static function (string $line): ?string {
            if (! str_starts_with($line, 'data:')) {
                return null;
            }
            $payload = trim(substr($line, strlen('data:')));
            if ($payload === '' || $payload === '[DONE]') {
                return null;
            }

            $data = json_decode($payload, true);

            return $data['choices'][0]['delta']['content'] ?? null;
        };
    }
}
