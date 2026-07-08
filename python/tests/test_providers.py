"""CONTRACT-05 — the provider fallback seam.

The AC in one line: simulate a Gemini 429 and the request still completes via
Groq, with the fallback order driven by the shared contract. These tests exercise
the policy with fake providers (no network) plus a MockTransport check that the
real Gemini client classifies HTTP statuses the way the policy expects.
"""

from __future__ import annotations

from typing import AsyncIterator

import httpx
import pytest

from app.providers import build_provider
from app.providers.base import FallbackProvider, ProviderError
from app.providers.gemini import GeminiProvider


class FakeProvider:
    """A scriptable Provider: either stream some tokens, or fail with a given
    ProviderError, on chat/embed."""

    def __init__(
        self, name, *, tokens=None, error=None, embedding=None, supports_embed=True
    ):
        self.name = name
        self.supports_embed = supports_embed
        self._tokens = tokens or []
        self._error = error
        self._embedding = embedding
        self.chat_calls = 0
        self.embed_calls = 0

    async def chat(self, prompt: str) -> AsyncIterator[str]:
        self.chat_calls += 1
        if self._error is not None:
            raise self._error
        for token in self._tokens:
            yield token

    async def embed(self, texts: list[str]) -> list[list[float]]:
        self.embed_calls += 1
        if self._error is not None:
            raise self._error
        return [self._embedding for _ in texts]


async def _collect(stream: AsyncIterator[str]) -> list[str]:
    return [chunk async for chunk in stream]


# ── chat fallback ────────────────────────────────────────────────────────────


async def test_chat_routes_to_fallback_on_retryable_error():
    primary = FakeProvider(
        "gemini", error=ProviderError("429", retryable=True, provider="gemini")
    )
    fallback = FakeProvider("groq", tokens=["Grounded ", "answer."])
    provider = FallbackProvider(chat_chain=[primary, fallback], embed_chain=[primary])

    assert await _collect(provider.chat("q?")) == ["Grounded ", "answer."]
    assert primary.chat_calls == 1 and fallback.chat_calls == 1


async def test_chat_prefers_primary_when_it_succeeds():
    primary = FakeProvider("gemini", tokens=["From ", "primary."])
    fallback = FakeProvider("groq", tokens=["nope"])
    provider = FallbackProvider(chat_chain=[primary, fallback], embed_chain=[primary])

    assert await _collect(provider.chat("q?")) == ["From ", "primary."]
    assert fallback.chat_calls == 0  # fallback never touched


async def test_chat_reraises_non_retryable_without_falling_back():
    primary = FakeProvider(
        "gemini", error=ProviderError("400", retryable=False, provider="gemini")
    )
    fallback = FakeProvider("groq", tokens=["should not run"])
    provider = FallbackProvider(chat_chain=[primary, fallback], embed_chain=[primary])

    with pytest.raises(ProviderError) as caught:
        await _collect(provider.chat("q?"))
    assert caught.value.status is None and not caught.value.retryable
    assert fallback.chat_calls == 0


async def test_chat_raises_last_error_when_all_exhausted():
    primary = FakeProvider(
        "gemini", error=ProviderError("429", retryable=True, provider="gemini")
    )
    fallback = FakeProvider(
        "groq", error=ProviderError("503", retryable=True, provider="groq")
    )
    provider = FallbackProvider(chat_chain=[primary, fallback], embed_chain=[primary])

    with pytest.raises(ProviderError) as caught:
        await _collect(provider.chat("q?"))
    assert caught.value.provider == "groq"


# ── embed fallback (Groq excluded — no embeddings API) ───────────────────────


async def test_embed_uses_only_embedding_capable_providers():
    gemini = FakeProvider("gemini", embedding=[0.1, 0.2], supports_embed=True)
    groq = FakeProvider("groq", supports_embed=False)
    # Mirror how the factory builds it: groq is filtered out of the embed chain.
    embed_chain = [p for p in (gemini, groq) if p.supports_embed]
    provider = FallbackProvider(chat_chain=[gemini, groq], embed_chain=embed_chain)

    assert await provider.embed(["a", "b"]) == [[0.1, 0.2], [0.1, 0.2]]
    assert groq.embed_calls == 0  # a chat-only backend is never asked to embed


# ── real Gemini client: HTTP status → retryable classification ───────────────


@pytest.mark.parametrize(
    "status, expect_retryable",
    [(429, True), (503, True), (500, True), (400, False), (403, False)],
)
async def test_gemini_classifies_http_status(status, expect_retryable):
    transport = httpx.MockTransport(
        lambda req: httpx.Response(status, json={"error": "x"})
    )
    client = httpx.AsyncClient(transport=transport)
    provider = GeminiProvider(api_key="test-key", client=client)

    with pytest.raises(ProviderError) as caught:
        await provider.embed(["hello"])
    assert caught.value.retryable is expect_retryable
    assert caught.value.status == status
    await client.aclose()


async def test_gemini_embed_returns_vectors_on_200():
    payload = {"embeddings": [{"values": [0.1, 0.2, 0.3]}, {"values": [0.4, 0.5, 0.6]}]}
    transport = httpx.MockTransport(lambda req: httpx.Response(200, json=payload))
    client = httpx.AsyncClient(transport=transport)
    provider = GeminiProvider(api_key="test-key", client=client)

    assert await provider.embed(["a", "b"]) == [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]
    await client.aclose()


# ── factory reads the chain order from the contract ──────────────────────────


def test_factory_builds_chains_from_contract_order():
    provider = build_provider()
    assert [p.name for p in provider.chat_chain] == ["gemini", "groq"]
    # Groq has no embeddings, so the embed chain is Gemini-only.
    assert [p.name for p in provider.embed_chain] == ["gemini"]
