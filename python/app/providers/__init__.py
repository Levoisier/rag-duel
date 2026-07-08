"""Provider abstraction for chat + embeddings (CONTRACT-05).

One small seam — `chat()` / `embed()` — with Gemini primary and Groq fallback on
429/5xx/timeout, in an order read from the shared contract. The PHP side mirrors
this exactly (laravel/app/Support/Providers/*), so the fallback order is identical
across engines — the fairness property CONTRACT-05 requires.

Why the two chains differ: Groq has no embeddings API (it serves chat models
only), so it can only ever be a *chat* fallback. The embed chain is therefore
Gemini-only. This asymmetry is between capabilities, not between engines — PHP and
Python resolve the same two chains from the same contract, which is what the
guardrail ("identical PHP vs Python") actually pins.
"""

from app.providers.base import FallbackProvider, Provider, ProviderError
from app.providers.factory import build_provider, build_throttled_provider
from app.providers.throttle import Throttle, ThrottledProvider, ThrottleError

__all__ = [
    "FallbackProvider",
    "Provider",
    "ProviderError",
    "Throttle",
    "ThrottleError",
    "ThrottledProvider",
    "build_provider",
    "build_throttled_provider",
]
