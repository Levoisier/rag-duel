"""Gemini provider — primary for both chat and embeddings (CONTRACT-05).

Fairness-critical parameters (model, dimension, temperature, max tokens) come from
the shared contract, never literals. The API key comes from the environment
(GEMINI_API_KEY), never the contract — secrets stay out of git (CONTRACT-04).
"""

from __future__ import annotations

import json
import os
from typing import AsyncIterator

import httpx

from app import contract
from app.providers.base import ProviderError

_BASE_URL = "https://generativelanguage.googleapis.com/v1beta"
_TIMEOUT = httpx.Timeout(30.0, connect=10.0)


def _classify(status: int) -> bool:
    """429 and 5xx are the transient failures the backlog falls back on; every
    other non-2xx is a real error a retry won't fix."""
    return status == 429 or status >= 500


class GeminiProvider:
    name = "gemini"
    supports_embed = True

    def __init__(
        self, *, api_key: str | None = None, client: httpx.AsyncClient | None = None
    ) -> None:
        self._api_key = api_key
        # An injected client is how tests swap in a MockTransport; production builds
        # a default one lazily so importing the module never opens a socket.
        self._client = client

    def _key(self) -> str:
        key = self._api_key or os.environ.get("GEMINI_API_KEY")
        if not key:
            raise ProviderError(
                "GEMINI_API_KEY is not set", retryable=False, provider=self.name
            )
        return key

    def _http(self) -> httpx.AsyncClient:
        if self._client is None:
            self._client = httpx.AsyncClient(timeout=_TIMEOUT)
        return self._client

    async def chat(self, prompt: str) -> AsyncIterator[str]:
        model = contract.get("LLM_MODEL")
        body = {
            "contents": [{"role": "user", "parts": [{"text": prompt}]}],
            "generationConfig": {
                "temperature": contract.get_float("LLM_TEMPERATURE"),
                "maxOutputTokens": contract.get_int("LLM_MAX_TOKENS"),
            },
        }
        url = f"{_BASE_URL}/models/{model}:streamGenerateContent"
        try:
            async with self._http().stream(
                "POST",
                url,
                params={"alt": "sse"},
                headers={"x-goog-api-key": self._key()},
                json=body,
            ) as response:
                if response.status_code != 200:
                    await response.aread()
                    raise ProviderError(
                        f"gemini chat HTTP {response.status_code}",
                        retryable=_classify(response.status_code),
                        provider=self.name,
                        status=response.status_code,
                    )
                async for line in response.aiter_lines():
                    text = _sse_text(line)
                    if text:
                        yield text
        except httpx.TimeoutException as error:
            raise ProviderError(
                f"gemini chat timeout: {error}", retryable=True, provider=self.name
            ) from error

    async def embed(self, texts: list[str]) -> list[list[float]]:
        model = contract.get("EMBEDDING_MODEL")
        dim = contract.get_int("EMBEDDING_DIM")
        requests = [
            {
                "model": f"models/{model}",
                "content": {"parts": [{"text": text}]},
                "outputDimensionality": dim,
            }
            for text in texts
        ]
        url = f"{_BASE_URL}/models/{model}:batchEmbedContents"
        try:
            response = await self._http().post(
                url,
                headers={"x-goog-api-key": self._key()},
                json={"requests": requests},
            )
        except httpx.TimeoutException as error:
            raise ProviderError(
                f"gemini embed timeout: {error}", retryable=True, provider=self.name
            ) from error

        if response.status_code != 200:
            raise ProviderError(
                f"gemini embed HTTP {response.status_code}",
                retryable=_classify(response.status_code),
                provider=self.name,
                status=response.status_code,
            )
        return [item["values"] for item in response.json()["embeddings"]]


def _sse_text(line: str) -> str | None:
    """Pull the answer text out of one Gemini SSE line (`data: {...}`). Returns
    None for keep-alives, blanks, and frames without text so the caller can skip
    them."""
    if not line.startswith("data:"):
        return None
    payload = line[len("data:") :].strip()
    if not payload or payload == "[DONE]":
        return None
    try:
        data = json.loads(payload)
        return data["candidates"][0]["content"]["parts"][0]["text"]
    except (KeyError, IndexError, json.JSONDecodeError):
        return None
