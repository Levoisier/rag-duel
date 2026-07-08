"""App-side request throttle (CONTRACT-06).

A safety cap that keeps a runaway loop from hammering the provider (and blowing
the free-tier quota). Both engines enforce the *same* ceiling from the shared
contract; the mechanism differs per runtime — a warm single process can count
in-memory here, whereas the PHP side (fresh php-fpm worker per request) counts
through Laravel's cache-backed RateLimiter. What must match is the ceiling and the
degrade-don't-hammer behaviour, not the bookkeeping.

When the cap would be exceeded we raise ThrottleError *without* making the call —
the endpoint turns that into a friendly "rate limit hit, try again later" frame.
"""

from __future__ import annotations

import time
from collections import deque
from typing import AsyncIterator, Callable

from app import contract
from app.providers.base import Provider

_MINUTE = 60.0
_DAY = 86_400.0


class ThrottleError(Exception):
    """The local throttle tripped before a network call was made. Its message is
    user-facing on purpose — it's what the graceful-degradation frame shows."""

    def __init__(self, message: str = "rate limit hit, try again later") -> None:
        super().__init__(message)


class Throttle:
    """Rolling-window counter over two horizons (per-minute, per-day). `check()`
    records one call and raises ThrottleError if either ceiling is already met, so
    the offending call never reaches the network. The clock is injectable so tests
    don't have to sleep."""

    def __init__(
        self,
        max_rpm: int,
        max_rpd: int,
        *,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        self._max_rpm = max_rpm
        self._max_rpd = max_rpd
        self._clock = clock
        self._minute: deque[float] = deque()
        self._day: deque[float] = deque()

    @classmethod
    def from_contract(
        cls, *, clock: Callable[[], float] = time.monotonic
    ) -> "Throttle":
        return cls(
            contract.get_int("PROVIDER_MAX_RPM"),
            contract.get_int("PROVIDER_MAX_RPD"),
            clock=clock,
        )

    def check(self) -> None:
        now = self._clock()
        _evict(self._minute, now - _MINUTE)
        _evict(self._day, now - _DAY)

        if len(self._minute) >= self._max_rpm or len(self._day) >= self._max_rpd:
            raise ThrottleError()

        self._minute.append(now)
        self._day.append(now)


def _evict(window: deque[float], cutoff: float) -> None:
    while window and window[0] <= cutoff:
        window.popleft()


class ThrottledProvider:
    """Wraps a provider so every chat/embed consults the throttle first. The
    wrapped provider is untouched — throttling is a separate concern layered on
    top of the fallback seam."""

    def __init__(self, inner: Provider, throttle: Throttle) -> None:
        self._inner = inner
        self._throttle = throttle

    async def chat(self, prompt: str) -> AsyncIterator[str]:
        self._throttle.check()
        async for chunk in self._inner.chat(prompt):
            yield chunk

    async def embed(self, texts: list[str]) -> list[list[float]]:
        self._throttle.check()
        return await self._inner.embed(texts)
