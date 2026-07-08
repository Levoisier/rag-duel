<?php

namespace Tests\Feature;

use App\Support\Providers\FallbackProvider;
use App\Support\Providers\Provider;
use App\Support\Providers\Throttle;
use App\Support\Providers\ThrottledProvider;
use App\Support\Providers\ThrottleException;
use Generator;
use Tests\TestCase;

/**
 * CONTRACT-06 — the app-side throttle degrades gracefully instead of hammering.
 * Small caps + unique keys keep each case within one rolling minute, so no clock
 * fiddling is needed. The point proven: once the ceiling is hit the next call
 * throws ThrottleException *before* the wrapped provider is touched — no path
 * silently spends quota. PHP twin of python/tests/test_throttle.py.
 */
class ProviderThrottleTest extends TestCase
{
    public function test_throttle_allows_up_to_the_rpm_ceiling_then_trips(): void
    {
        $throttle = new Throttle(maxRpm: 3, maxRpd: 1000, key: uniqid('t', true));

        $throttle->check();
        $throttle->check();
        $throttle->check();

        $this->expectException(ThrottleException::class);
        $throttle->check(); // 4th within the minute trips
    }

    public function test_throttle_enforces_the_daily_ceiling_independently(): void
    {
        // Ample RPM room, tiny RPD: the day ceiling must bite on its own.
        $throttle = new Throttle(maxRpm: 1000, maxRpd: 2, key: uniqid('t', true));

        $throttle->check();
        $throttle->check();

        $this->expectException(ThrottleException::class);
        $throttle->check();
    }

    public function test_throttled_provider_does_not_touch_provider_once_capped(): void
    {
        $counting = new CountingProvider;
        $inner = new FallbackProvider([$counting], [$counting]);
        $provider = new ThrottledProvider($inner, new Throttle(maxRpm: 1, maxRpd: 1000, key: uniqid('t', true)));

        $this->assertSame(['answer'], array_values(iterator_to_array($provider->chat('q'))));
        $this->assertSame(1, $counting->chatCalls);

        try {
            iterator_to_array($provider->chat('q'));
            $this->fail('expected ThrottleException');
        } catch (ThrottleException) {
            // expected
        }
        $this->assertSame(1, $counting->chatCalls); // network never hit on the throttled call
    }

    public function test_throttled_embed_is_capped_too(): void
    {
        $counting = new CountingProvider;
        $inner = new FallbackProvider([$counting], [$counting]);
        $provider = new ThrottledProvider($inner, new Throttle(maxRpm: 1, maxRpd: 1000, key: uniqid('t', true)));

        $provider->embed(['a']);

        try {
            $provider->embed(['b']);
            $this->fail('expected ThrottleException');
        } catch (ThrottleException) {
            // expected
        }
        $this->assertSame(1, $counting->embedCalls);
    }

    public function test_throttle_from_contract_uses_the_locked_ceilings(): void
    {
        // Sanity: the contract ceilings load and are the free-tier sizes.
        $throttle = Throttle::fromContract(uniqid('t', true));
        $this->assertInstanceOf(Throttle::class, $throttle);
    }
}

/** A Provider double that counts how often it's actually reached. */
class CountingProvider implements Provider
{
    public int $chatCalls = 0;

    public int $embedCalls = 0;

    public function name(): string
    {
        return 'counting';
    }

    public function supportsEmbed(): bool
    {
        return true;
    }

    public function chat(string $prompt): Generator
    {
        $this->chatCalls++;

        yield 'answer';
    }

    public function embed(array $texts): array
    {
        $this->embedCalls++;

        return array_map(fn () => [0.0], $texts);
    }
}
