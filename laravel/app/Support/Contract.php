<?php

namespace App\Support;

use RuntimeException;

/**
 * Reader for infra/contract.env — the shared PHP↔Python parameter contract
 * (CONTRACT-01). Every fairness-critical value (embedding model + dim, LLM
 * params, TOP_K, chunk sizes, metric) must be fetched through here; a literal
 * in engine code is a contract violation that invalidates the comparison.
 *
 * Deliberately fails hard on a missing file or key: silently defaulting is
 * exactly how the two engines would drift apart unnoticed.
 */
final class Contract
{
    /** @var array<string, string>|null */
    private static ?array $values = null;

    public static function get(string $key): string
    {
        self::$values ??= self::parse();

        return self::$values[$key]
            ?? throw new RuntimeException("Contract key [{$key}] missing from infra/contract.env — add it there, never default in code.");
    }

    public static function int(string $key): int
    {
        $raw = self::get($key);

        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new RuntimeException("Contract key [{$key}] expected an integer, got [{$raw}].");
        }

        return (int) $raw;
    }

    public static function float(string $key): float
    {
        $raw = self::get($key);

        if (! is_numeric($raw)) {
            throw new RuntimeException("Contract key [{$key}] expected a number, got [{$raw}].");
        }

        return (float) $raw;
    }

    /**
     * A comma-separated contract value as an ordered list (e.g. TIMINGS_KEYS,
     * CONTRACT-03). Order is preserved because the metrics frame is defined in a
     * canonical order both engines must honor.
     *
     * @return list<string>
     */
    public static function list(string $key): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', self::get($key))),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /** The contract lives at the repo root, one level above the Laravel app. */
    public static function path(): string
    {
        return dirname(base_path()).'/infra/contract.env';
    }

    /** Reset the memoized values (tests that swap the file need this). */
    public static function flush(): void
    {
        self::$values = null;
    }

    /** @return array<string, string> */
    private static function parse(): array
    {
        $path = self::path();

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException("Shared contract not found at {$path} (CONTRACT-01).");
        }

        $values = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Full-line comments only, per the header rule in contract.env.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $values[trim($key)] = trim($value);
        }

        return $values;
    }
}
