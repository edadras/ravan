"""Per-session, per-person robust baseline.

The baseline is the first ``window_s`` seconds of adequate-quality data for each
feature and speaker state.  Statistics are robust (median / MAD) so that a few
outlier frames do not define "normal" for the session.  Population norms are
never used.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field

import numpy as np

MAD_TO_SIGMA = 1.4826


@dataclass
class FeatureBaseline:
    median: float
    sigma: float
    n: int
    rate_per_min: float | None = None  # for event features

    def z(self, value: float) -> float:
        if self.sigma <= 0:
            return 0.0
        return (value - self.median) / self.sigma

    def ratio(self, value: float) -> float:
        if abs(self.median) < 1e-9:
            return math.inf if value > 0 else 1.0
        return value / self.median


@dataclass
class Baseline:
    window_s: float = 300.0
    min_samples: int = 30
    _samples: dict[tuple[str, str], list[float]] = field(default_factory=dict)
    _event_counts: dict[tuple[str, str], int] = field(default_factory=dict)
    _covered_s: float = 0.0
    _first_t: float | None = None
    _quality_frames: int = 0
    _total_frames: int = 0
    frozen: bool = False
    _stats: dict[tuple[str, str], FeatureBaseline] = field(default_factory=dict)

    # ------------------------------------------------------------------ collection
    def observe(self, t_s: float, feature: str, value: float, state: str, quality_ok: bool) -> None:
        if self.frozen:
            return
        if self._first_t is None:
            self._first_t = t_s
        self._covered_s = t_s - self._first_t
        self._total_frames += 1
        if not quality_ok:
            return
        self._quality_frames += 1
        for st in (state, "any"):
            self._samples.setdefault((feature, st), []).append(float(value))
        if self._covered_s >= self.window_s:
            self.freeze()

    def observe_event(self, t_s: float, feature: str, state: str) -> None:
        if self.frozen:
            return
        for st in (state, "any"):
            self._event_counts[(feature, st)] = self._event_counts.get((feature, st), 0) + 1

    def reset_geometry(self) -> None:
        """Called after camera_moved: geometry-dependent features must be re-baselined."""
        geometry = ("head_tx", "head_ty", "head_scale", "body_center_x", "body_center_y", "body_scale",
                    "torso_angle", "torso_lateral_angle", "torso_yaw", "shoulder_height_left",
                    "shoulder_height_right", "shoulder_asymmetry", "gaze_x", "gaze_y", "head_yaw", "head_pitch", "head_roll")
        for key in list(self._samples):
            if key[0] in geometry:
                self._samples.pop(key)
                self._stats.pop(key, None)
        self.frozen = False
        self._first_t = None
        self._covered_s = 0.0

    # ------------------------------------------------------------------ status
    @property
    def ready(self) -> bool:
        return self.frozen

    @property
    def coverage_s(self) -> float:
        return self._covered_s

    @property
    def quality_fraction(self) -> float:
        return self._quality_frames / self._total_frames if self._total_frames else 0.0

    def freeze(self) -> None:
        self.frozen = True
        self._stats = {}
        minutes = max(self._covered_s / 60.0, 1e-6)
        for key, vals in self._samples.items():
            arr = np.asarray(vals, dtype=float)
            if arr.size == 0:
                continue
            med = float(np.median(arr))
            mad = float(np.median(np.abs(arr - med)))
            sigma = MAD_TO_SIGMA * mad
            if sigma < 1e-9:  # degenerate: fall back to std, then to a small relative floor
                sigma = float(arr.std()) or max(abs(med) * 0.05, 1e-3)
            self._stats[key] = FeatureBaseline(median=med, sigma=sigma, n=int(arr.size))
        for key, count in self._event_counts.items():
            fb = self._stats.get(key) or FeatureBaseline(median=0.0, sigma=1.0, n=count)
            fb.rate_per_min = count / minutes
            self._stats[key] = fb

    def get(self, feature: str, state: str = "any") -> FeatureBaseline | None:
        if not self.frozen:
            return None
        return self._stats.get((feature, state)) or self._stats.get((feature, "any"))

    def summary(self) -> dict:
        return {
            "ready": self.ready,
            "coverage_s": round(self._covered_s, 1),
            "quality_fraction": round(self.quality_fraction, 3),
            "features": {f"{k[0]}@{k[1]}": {"median": round(v.median, 4), "sigma": round(v.sigma, 4), "n": v.n,
                                             "rate_per_min": None if v.rate_per_min is None else round(v.rate_per_min, 3)}
                         for k, v in self._stats.items() if k[1] == "any"},
        }
