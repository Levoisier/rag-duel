"""Groq provider — chat-only fallback (CONTRACT-05).

Groq serves an OpenAI-compatible chat API and *no* embeddings, so `supports_embed`
is False and the fallback wrapper keeps it out of the embed chain. Temperature and
max-tokens still come from the shared contract so the degraded path stays as close
to the primary as the model allows; the Groq model id and key are environment
config (GROQ_MODEL / GROQ_API_KEY) — a fallback model isn't a fairness parameter,
and keys never live in the contract.
"""

from __future__ import annotations

import json
import os
from typing import AsyncIterator

import httpx

from app import contract
from app.providers.base import ProviderError

_URL = "https://api.groq.com/openai/v1/chat/completions"
_DEFAULT_MODEL = "llama-3.3-70b-versatile"
_TIMEOUT = httpx.Timeout(30.0, connect=10.0)


def _classify(status: int) -> bool:
    return status == 429 or status >= 500


class GroqProvider:
    name = "groq"
    supports_embed = False

    def __init__(
        self, *, api_key: str | None = None, client: httpx.AsyncClient | None = None
    ) -> None:
        self._api_key = api_key
        self._client = client

    def _key(self) -> str:
        key = self._api_key or os.environ.get("GROQ_API_KEY")
        if not key:
            raise ProviderError(
                "GROQ_API_KEY is not set", retryable=False, provider=self.name
            )
        return key

    def _http(self) -> httpx.AsyncClient:
        if self._client is None:
            self._client = httpx.AsyncClient(timeout=_TIMEOUT)
        return self._client

    async def chat(self, prompt: str) -> AsyncIterator[str]:
        body = {
            "model": os.environ.get("GROQ_MODEL", _DEFAULT_MODEL),
            "messages": [{"role": "user", "content": prompt}],
            "temperature": contract.get_float("LLM_TEMPERATURE"),
            "max_tokens": contract.get_int("LLM_MAX_TOKENS"),
            "stream": True,
        }
        try:
            async with self._http().stream(
                "POST",
                _URL,
                headers={"Authorization": f"Bearer {self._key()}"},
                json=body,
            ) as response:
                if response.status_code != 200:
                    await response.aread()
                    raise ProviderError(
                        f"groq chat HTTP {response.status_code}",
                        retryable=_classify(response.status_code),
                        provider=self.name,
                        status=response.status_code,
                    )
                async for line in response.aiter_lines():
                    text = _sse_delta(line)
                    if text:
                        yield text
        except httpx.TimeoutException as error:
            raise ProviderError(
                f"groq chat timeout: {error}", retryable=True, provider=self.name
            ) from error

    async def embed(self, texts: list[str]) -> list[list[float]]:
        # Should never be reached — the fallback wrapper excludes chat-only
        # providers from the embed chain — but fail loud if wiring ever regresses.
        raise ProviderError(
            "groq has no embeddings API", retryable=False, provider=self.name
        )


def _sse_delta(line: str) -> str | None:
    """Pull one token out of a Groq (OpenAI-style) SSE line. `[DONE]` and
    content-less frames return None."""
    if not line.startswith("data:"):
        return None
    payload = line[len("data:") :].strip()
    if not payload or payload == "[DONE]":
        return None
    try:
        return json.loads(payload)["choices"][0]["delta"].get("content")
    except (KeyError, IndexError, json.JSONDecodeError):
        return None
