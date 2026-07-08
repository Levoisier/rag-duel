<?php

namespace App\Support\Providers;

use Generator;

/**
 * A single backend (Gemini or Groq) behind the shared seam (CONTRACT-05).
 * `chat()` streams answer chunks; `embed()` returns one vector per input text.
 * `supportsEmbed()` is how the fallback wrapper knows to leave a chat-only
 * backend (Groq) out of the embed chain.
 *
 * Mirrors python/app/providers/base.py::Provider — same two methods, same
 * capability flag — so the fallback order is identical across engines.
 */
interface Provider
{
    public function name(): string;

    public function supportsEmbed(): bool;

    /** @return Generator<int, string> stream of answer chunks */
    public function chat(string $prompt): Generator;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>> one vector per input text
     */
    public function embed(array $texts): array;
}
