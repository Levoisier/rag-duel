<?php

namespace Tests\Feature;

use App\Support\Providers\FallbackProvider;
use App\Support\Providers\GeminiProvider;
use App\Support\Providers\Provider;
use App\Support\Providers\ProviderException;
use App\Support\Providers\ProviderFactory;
use Generator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CONTRACT-05 — the provider fallback seam. The AC in one line: simulate a Gemini
 * 429 and the request still completes via Groq, with the fallback order driven by
 * the shared contract. Fake providers exercise the policy with no network; Http::fake
 * proves the real Gemini client classifies HTTP statuses the way the policy expects.
 *
 * This is the PHP twin of python/tests/test_providers.py — same cases, same order.
 */
class ProviderFallbackTest extends TestCase
{
    // ── chat fallback ────────────────────────────────────────────────────────

    public function test_chat_routes_to_fallback_on_retryable_error(): void
    {
        $primary = new FakeProvider('gemini', chatError: new ProviderException('429', retryable: true, provider: 'gemini'));
        $fallback = new FakeProvider('groq', tokens: ['Grounded ', 'answer.']);
        $provider = new FallbackProvider([$primary, $fallback], [$primary]);

        $this->assertSame(['Grounded ', 'answer.'], array_values(iterator_to_array($provider->chat('q?'))));
        $this->assertSame(1, $primary->chatCalls);
        $this->assertSame(1, $fallback->chatCalls);
    }

    public function test_chat_prefers_primary_when_it_succeeds(): void
    {
        $primary = new FakeProvider('gemini', tokens: ['From ', 'primary.']);
        $fallback = new FakeProvider('groq', tokens: ['nope']);
        $provider = new FallbackProvider([$primary, $fallback], [$primary]);

        $this->assertSame(['From ', 'primary.'], array_values(iterator_to_array($provider->chat('q?'))));
        $this->assertSame(0, $fallback->chatCalls); // fallback never touched
    }

    public function test_chat_reraises_non_retryable_without_falling_back(): void
    {
        $primary = new FakeProvider('gemini', chatError: new ProviderException('400', retryable: false, provider: 'gemini'));
        $fallback = new FakeProvider('groq', tokens: ['should not run']);
        $provider = new FallbackProvider([$primary, $fallback], [$primary]);

        try {
            iterator_to_array($provider->chat('q?'));
            $this->fail('expected ProviderException');
        } catch (ProviderException $error) {
            $this->assertFalse($error->retryable);
        }
        $this->assertSame(0, $fallback->chatCalls);
    }

    public function test_chat_raises_last_error_when_all_exhausted(): void
    {
        $primary = new FakeProvider('gemini', chatError: new ProviderException('429', retryable: true, provider: 'gemini'));
        $fallback = new FakeProvider('groq', chatError: new ProviderException('503', retryable: true, provider: 'groq'));
        $provider = new FallbackProvider([$primary, $fallback], [$primary]);

        try {
            iterator_to_array($provider->chat('q?'));
            $this->fail('expected ProviderException');
        } catch (ProviderException $error) {
            $this->assertSame('groq', $error->provider);
        }
    }

    // ── embed fallback (Groq excluded — no embeddings API) ───────────────────

    public function test_embed_uses_only_embedding_capable_providers(): void
    {
        $gemini = new FakeProvider('gemini', embedding: [0.1, 0.2], supportsEmbed: true);
        $groq = new FakeProvider('groq', supportsEmbed: false);
        $embedChain = array_values(array_filter([$gemini, $groq], fn (Provider $p) => $p->supportsEmbed()));
        $provider = new FallbackProvider([$gemini, $groq], $embedChain);

        $this->assertSame([[0.1, 0.2], [0.1, 0.2]], $provider->embed(['a', 'b']));
        $this->assertSame(0, $groq->embedCalls); // a chat-only backend is never asked to embed
    }

    // ── real Gemini client: HTTP status → retryable classification ───────────

    #[DataProvider('httpStatusCases')]
    public function test_gemini_classifies_http_status(int $status, bool $expectRetryable): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'x'], $status)]);
        $provider = new GeminiProvider('test-key');

        try {
            $provider->embed(['hello']);
            $this->fail('expected ProviderException');
        } catch (ProviderException $error) {
            $this->assertSame($expectRetryable, $error->retryable);
            $this->assertSame($status, $error->status);
        }
    }

    /** @return array<string, array{int, bool}> */
    public static function httpStatusCases(): array
    {
        return [
            '429 rate limit' => [429, true],
            '503 unavailable' => [503, true],
            '500 server error' => [500, true],
            '400 bad request' => [400, false],
            '403 forbidden' => [403, false],
        ];
    }

    public function test_gemini_embed_returns_vectors_on_200(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            ['embeddings' => [['values' => [0.1, 0.2, 0.3]], ['values' => [0.4, 0.5, 0.6]]]], 200,
        )]);

        $this->assertSame(
            [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
            (new GeminiProvider('test-key'))->embed(['a', 'b']),
        );
    }

    // ── factory reads the chain order from the contract ──────────────────────

    public function test_factory_builds_chains_from_contract_order(): void
    {
        $provider = ProviderFactory::make();

        $this->assertSame(
            ['gemini', 'groq'],
            array_map(fn (Provider $p) => $p->name(), $provider->chatChain),
        );
        // Groq has no embeddings, so the embed chain is Gemini-only.
        $this->assertSame(
            ['gemini'],
            array_map(fn (Provider $p) => $p->name(), $provider->embedChain),
        );
    }
}

/**
 * A scriptable Provider double: stream some tokens, or fail with a given
 * ProviderException, on chat/embed. Mirrors the FakeProvider in the Python tests.
 */
class FakeProvider implements Provider
{
    public int $chatCalls = 0;

    public int $embedCalls = 0;

    /**
     * @param  list<string>  $tokens
     * @param  list<float>|null  $embedding
     */
    public function __construct(
        private string $providerName,
        private array $tokens = [],
        private ?ProviderException $chatError = null,
        private ?ProviderException $embedError = null,
        private ?array $embedding = null,
        private bool $supportsEmbed = true,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function supportsEmbed(): bool
    {
        return $this->supportsEmbed;
    }

    public function chat(string $prompt): Generator
    {
        $this->chatCalls++;

        if ($this->chatError !== null) {
            throw $this->chatError;
        }

        foreach ($this->tokens as $token) {
            yield $token;
        }
    }

    public function embed(array $texts): array
    {
        $this->embedCalls++;

        if ($this->embedError !== null) {
            throw $this->embedError;
        }

        return array_map(fn () => $this->embedding, $texts);
    }
}
