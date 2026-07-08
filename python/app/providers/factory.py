"""Build the fallback provider from the shared contract order.

The chain order is read from AI_PROVIDER_PRIMARY / AI_PROVIDER_FALLBACK — the same
two keys the PHP factory reads — so neither engine hardcodes "gemini then groq".
The embed chain is the same chain filtered to embedding-capable providers.
"""

from __future__ import annotations

from app import contract
from app.providers.base import FallbackProvider, Provider
from app.providers.gemini import GeminiProvider
from app.providers.groq import GroqProvider

_REGISTRY = {
    "gemini": GeminiProvider,
    "groq": GroqProvider,
}


def _instantiate(name: str) -> Provider:
    try:
        return _REGISTRY[name]()
    except KeyError as exc:
        raise RuntimeError(
            f"Unknown provider [{name}] in contract — known: {sorted(_REGISTRY)}."
        ) from exc


def build_provider() -> FallbackProvider:
    chain = [
        _instantiate(contract.get("AI_PROVIDER_PRIMARY")),
        _instantiate(contract.get("AI_PROVIDER_FALLBACK")),
    ]
    embed_chain = [provider for provider in chain if provider.supports_embed]
    return FallbackProvider(chat_chain=chain, embed_chain=embed_chain)
