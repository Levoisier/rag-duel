"""The provider seam: the error type, the interface, and the fallback wrapper.

Concrete providers (Gemini, Groq) live in sibling modules; this file is the part
that must behave byte-for-byte like the PHP side, so it carries the fallback
policy and error classification and nothing provider-specific.
"""

from __future__ import annotations

from typing import AsyncIterator, Protocol, runtime_checkable


class ProviderError(Exception):
    """A provider call failed. `retryable` is the single fact the fallback policy
    keys on: True for the transient failures the backlog names (429 / 5xx /
    timeout) — try the next provider — False for anything else (bad request, auth,
    a 4xx that a retry won't fix) — surface it, don't mask a real bug behind a
    fallback."""

    def __init__(
        self,
        message: str,
        *,
        retryable: bool,
        provider: str,
        status: int | None = None,
    ) -> None:
        super().__init__(message)
        self.retryable = retryable
        self.provider = provider
        self.status = status


@runtime_checkable
class Provider(Protocol):
    """A single backend. `chat` streams answer chunks; `embed` returns one vector
    per input text. `supports_embed` is how the fallback wrapper knows to leave a
    chat-only backend (Groq) out of the embed chain."""

    name: str
    supports_embed: bool

    def chat(self, prompt: str) -> AsyncIterator[str]: ...

    async def embed(self, texts: list[str]) -> list[list[float]]: ...


class FallbackProvider:
    """Tries providers in order, advancing to the next only on a *retryable*
    ProviderError. Same policy for chat and embed; the chains differ only in
    membership (embed excludes non-embedding backends).

    Streaming caveat: chat can only fall back *before the first token*, because
    once tokens are on the wire we can't un-send them. That matches reality —
    429/5xx are returned on the initial request, not mid-stream — so we open the
    upstream and pull its first chunk under the try; a retryable failure there
    routes to the next provider transparently. A failure after tokens have flowed
    propagates rather than double-answering."""

    def __init__(
        self,
        chat_chain: list[Provider],
        embed_chain: list[Provider],
    ) -> None:
        self.chat_chain = chat_chain
        self.embed_chain = embed_chain

    async def chat(self, prompt: str) -> AsyncIterator[str]:
        last_error: ProviderError | None = None

        for provider in self.chat_chain:
            stream = provider.chat(prompt)
            try:
                first = await stream.__anext__()
            except StopAsyncIteration:
                return  # succeeded with an empty answer — a valid completion
            except ProviderError as error:
                if error.retryable:
                    last_error = error
                    continue
                raise

            # First token landed: this provider owns the rest of the stream.
            yield first
            async for chunk in stream:
                yield chunk
            return

        raise last_error or ProviderError(
            "no chat provider configured", retryable=False, provider="fallback"
        )

    async def embed(self, texts: list[str]) -> list[list[float]]:
        last_error: ProviderError | None = None

        for provider in self.embed_chain:
            try:
                return await provider.embed(texts)
            except ProviderError as error:
                if error.retryable:
                    last_error = error
                    continue
                raise

        raise last_error or ProviderError(
            "no embedding provider configured", retryable=False, provider="fallback"
        )
