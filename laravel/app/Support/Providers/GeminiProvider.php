<?php

namespace App\Support\Providers;

use App\Support\Contract;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Gemini — primary for both chat and embeddings (CONTRACT-05). Fairness-critical
 * parameters (model, dimension, temperature, max tokens) come from the shared
 * contract, never literals. The API key comes from config/env (never the
 * contract) — secrets stay out of git (CONTRACT-04).
 *
 * Twin of python/app/providers/gemini.py; same endpoints, same status
 * classification.
 */
final class GeminiProvider implements Provider
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(private readonly ?string $apiKey = null) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function supportsEmbed(): bool
    {
        return true;
    }

    public function chat(string $prompt): Generator
    {
        $model = Contract::get('LLM_MODEL');
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => Contract::float('LLM_TEMPERATURE'),
                'maxOutputTokens' => Contract::int('LLM_MAX_TOKENS'),
            ],
        ];

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->key()])
                ->withOptions(['stream' => true])
                ->timeout(30)
                ->post(self::BASE_URL."/models/{$model}:streamGenerateContent?alt=sse", $body);
        } catch (ConnectionException $error) {
            throw new ProviderException(
                "gemini chat timeout: {$error->getMessage()}", retryable: true, provider: $this->name(),
            );
        }

        if ($response->status() !== 200) {
            throw new ProviderException(
                "gemini chat HTTP {$response->status()}",
                retryable: self::classify($response->status()),
                provider: $this->name(),
                status: $response->status(),
            );
        }

        yield from self::streamSse($response->toPsrResponse()->getBody(), self::sseTextExtractor());
    }

    public function embed(array $texts): array
    {
        $model = Contract::get('EMBEDDING_MODEL');
        $dim = Contract::int('EMBEDDING_DIM');
        $requests = array_map(fn (string $text): array => [
            'model' => "models/{$model}",
            'content' => ['parts' => [['text' => $text]]],
            'outputDimensionality' => $dim,
        ], $texts);

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->key()])
                ->timeout(30)
                ->post(self::BASE_URL."/models/{$model}:batchEmbedContents", ['requests' => $requests]);
        } catch (ConnectionException $error) {
            throw new ProviderException(
                "gemini embed timeout: {$error->getMessage()}", retryable: true, provider: $this->name(),
            );
        }

        if ($response->status() !== 200) {
            throw new ProviderException(
                "gemini embed HTTP {$response->status()}",
                retryable: self::classify($response->status()),
                provider: $this->name(),
                status: $response->status(),
            );
        }

        return array_map(
            static fn (array $item): array => $item['values'],
            $response->json('embeddings', []),
        );
    }

    private function key(): string
    {
        $key = $this->apiKey ?? config('services.gemini.key');

        if (empty($key)) {
            throw new ProviderException(
                'GEMINI_API_KEY is not set', retryable: false, provider: $this->name(),
            );
        }

        return $key;
    }

    /** 429 and 5xx are the transient failures the backlog falls back on. */
    private static function classify(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * Read an SSE body line by line, yielding whatever the extractor pulls from
     * each `data:` frame. Shared by chat() here and GroqProvider.
     *
     * @param  callable(string): (string|null)  $extract
     * @return Generator<int, string>
     */
    public static function streamSse(StreamInterface $stream, callable $extract): Generator
    {
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);

                $text = $extract(trim($line));
                if ($text !== null && $text !== '') {
                    yield $text;
                }
            }
        }
    }

    /** @return callable(string): (string|null) */
    private static function sseTextExtractor(): callable
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

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        };
    }
}
