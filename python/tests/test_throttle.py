"""CONTRACT-06 — the app-side throttle degrades gracefully instead of hammering.

A fake clock drives the rolling windows so the tests are deterministic (no
sleeping). The point being proven: once the ceiling is hit, the next call raises
ThrottleError *before* the wrapped provider is ever touched — i.e. no path
silently spends quota.
"""

from __future__ import annotations

from typing import AsyncIterator

import pytest

from app.providers.throttle import Throttle, ThrottledProvider, ThrottleError


class Clock:
    def __init__(self) -> None:
        self.now = 0.0

    def __call__(self) -> float:
        return self.now


def test_throttle_allows_up_to_the_rpm_ceiling_then_trips():
    clock = Clock()
    throttle = Throttle(max_rpm=3, max_rpd=1000, clock=clock)

    for _ in range(3):
        throttle.check()  # 3 allowed
    with pytest.raises(ThrottleError):
        throttle.check()  # 4th within the same minute trips


def test_throttle_window_slides_so_calls_recover_after_a_minute():
    clock = Clock()
    throttle = Throttle(max_rpm=2, max_rpd=1000, clock=clock)

    throttle.check()
    throttle.check()
    with pytest.raises(ThrottleError):
        throttle.check()

    clock.now += 61  # the earlier hits age out of the minute window
    throttle.check()  # allowed again


def test_throttle_enforces_the_daily_ceiling_independently():
    clock = Clock()
    throttle = Throttle(max_rpm=1000, max_rpd=2, clock=clock)

    throttle.check()
    clock.now += 120  # clear of the minute window, still within the day
    throttle.check()
    clock.now += 120
    with pytest.raises(ThrottleError):
        throttle.check()  # third call today is refused despite ample RPM room


class CountingProvider:
    def __init__(self) -> None:
        self.chat_calls = 0
        self.embed_calls = 0

    async def chat(self, prompt: str) -> AsyncIterator[str]:
        self.chat_calls += 1
        yield "answer"

    async def embed(self, texts: list[str]) -> list[list[float]]:
        self.embed_calls += 1
        return [[0.0] for _ in texts]


async def test_throttled_provider_does_not_touch_provider_once_capped():
    clock = Clock()
    inner = CountingProvider()
    provider = ThrottledProvider(inner, Throttle(max_rpm=1, max_rpd=1000, clock=clock))

    assert [chunk async for chunk in provider.chat("q")] == ["answer"]
    assert inner.chat_calls == 1

    with pytest.raises(ThrottleError):
        # A generator body doesn't run until iterated — force it.
        [chunk async for chunk in provider.chat("q")]
    assert inner.chat_calls == 1  # the network was never hit on the throttled call


async def test_throttled_embed_is_capped_too():
    clock = Clock()
    inner = CountingProvider()
    provider = ThrottledProvider(inner, Throttle(max_rpm=1, max_rpd=1000, clock=clock))

    await provider.embed(["a"])
    with pytest.raises(ThrottleError):
        await provider.embed(["b"])
    assert inner.embed_calls == 1
