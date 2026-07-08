"""Reader for infra/contract.env — the shared PHP<->Python parameter contract.

The Python-side twin of laravel/app/Support/Contract.php: same file, same parse
rules (full-line `#` comments only, plain KEY=VALUE, no quoting/interpolation),
so both engines resolve every fairness-critical value from one source. A literal
in engine code is a contract violation that invalidates the comparison, exactly
as on the PHP side.

Deliberately fails hard on a missing file or key: a silent default is precisely
how the two engines would drift apart unnoticed (CONTRACT-01).
"""

from functools import lru_cache
from pathlib import Path

# The contract lives at the repo root, two levels above this module
# (python/app/contract.py -> repo root -> infra/contract.env).
_CONTRACT_PATH = Path(__file__).resolve().parents[2] / "infra" / "contract.env"


@lru_cache(maxsize=1)
def _values() -> dict[str, str]:
    if not _CONTRACT_PATH.is_file():
        raise RuntimeError(
            f"Shared contract not found at {_CONTRACT_PATH} (CONTRACT-01)."
        )

    values: dict[str, str] = {}
    for line in _CONTRACT_PATH.read_text().splitlines():
        line = line.strip()
        # Full-line comments only, per the header rule in contract.env.
        if not line or line.startswith("#"):
            continue
        key, _, value = line.partition("=")
        values[key.strip()] = value.strip()
    return values


def get(key: str) -> str:
    try:
        return _values()[key]
    except KeyError as exc:
        raise RuntimeError(
            f"Contract key [{key}] missing from infra/contract.env — add it there, never default in code."
        ) from exc


def get_int(key: str) -> int:
    raw = get(key)
    if not raw.lstrip("-").isdigit():
        raise RuntimeError(f"Contract key [{key}] expected an integer, got [{raw}].")
    return int(raw)


def get_float(key: str) -> float:
    raw = get(key)
    try:
        return float(raw)
    except ValueError as exc:
        raise RuntimeError(
            f"Contract key [{key}] expected a number, got [{raw}]."
        ) from exc


def get_list(key: str) -> list[str]:
    """A comma-separated contract value as an ordered list (e.g. TIMINGS_KEYS,
    CONTRACT-03). Order is preserved because the metrics frame is defined in a
    canonical order both engines must honor."""
    return [item.strip() for item in get(key).split(",") if item.strip()]
