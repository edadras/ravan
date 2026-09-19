"""Load and index the behaviour-signal catalog."""

from __future__ import annotations

import json
import os
from functools import lru_cache
from typing import Any

DEFAULT_PATHS = [
    os.environ.get("RAVAN_CATALOG_PATH", ""),
    os.path.join(os.path.dirname(__file__), "..", "..", "catalog", "signal_catalog.json"),
    "/app/catalog/signal_catalog.json",
]


class Catalog:
    def __init__(self, data: dict[str, Any]):
        self.data = data
        self.version: str = data["schema_version"]
        self.signals: dict[str, dict] = {s["id"]: s for s in data["signals"]}
        self.by_group: dict[str, list[dict]] = {}
        for s in data["signals"]:
            self.by_group.setdefault(s["group"], []).append(s)
        self.forbidden_labels: set[str] = set()
        for s in data["signals"]:
            self.forbidden_labels.update(s.get("forbidden_labels", []))
        self.contexts: dict[str, dict] = {c["key"]: c for c in data["contexts"]}
        self.baseline_window_s: float = float(data["principles"].get("baseline_window_s", 300))

    def signal(self, signal_id: str) -> dict:
        return self.signals[signal_id]

    def detectable(self) -> list[dict]:
        """Signals evaluated by the streaming engine (everything except cluster/content, handled separately)."""
        return [s for s in self.signals.values() if s["detector"]["type"] not in ("cluster", "content")]

    def clusters(self) -> list[dict]:
        return [s for s in self.signals.values() if s["detector"]["type"] == "cluster"]

    def content_signals(self) -> list[dict]:
        return [s for s in self.signals.values() if s["detector"]["type"] == "content"]


@lru_cache(maxsize=1)
def load_catalog(path: str | None = None) -> Catalog:
    candidates = [path] if path else DEFAULT_PATHS
    for p in candidates:
        if p and os.path.exists(p):
            with open(p, encoding="utf-8") as fh:
                return Catalog(json.load(fh))
    raise FileNotFoundError(f"signal_catalog.json not found in {candidates}")
