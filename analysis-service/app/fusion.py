"""Multimodal fusion: turn co-occurring single-channel events into cluster events.

A cluster is evidence that several *independent* observable channels changed
near the same moment.  It carries the member event ids so the clinician can
inspect each one; it never carries an interpretation.
"""

from __future__ import annotations

from dataclasses import dataclass

from .schemas import BehaviorEvent

NON_MEMBER_TIERS = {"quality", "cluster"}


@dataclass
class ClusterResult:
    signal_id: str
    t_start_ms: int
    t_end_ms: int
    members: list[BehaviorEvent]
    question_id: str | None
    confidence: float


class ClusterFuser:
    def __init__(self, cluster_signals: list[dict]):
        self.cluster_signals = cluster_signals
        self._last_fired: dict[str, float] = {}

    def evaluate(self, now_ms: int, recent: list[BehaviorEvent], last_question: dict | None) -> list[ClusterResult]:
        out: list[ClusterResult] = []
        for cs in self.cluster_signals:
            det = cs["detector"]
            window_ms = int(det["window_s"] * 1000)
            cooldown_ms = int(det["cooldown_s"] * 1000)
            if now_ms - self._last_fired.get(cs["id"], -1e12) < cooldown_ms:
                continue
            members_spec = det.get("member_signals", [])
            candidates = [e for e in recent
                          if now_ms - e.t_end_ms <= window_ms
                          and e.tier not in NON_MEMBER_TIERS
                          and (members_spec == ["*"] or e.signal_id in members_spec)]
            # one member per signal (the strongest)
            best: dict[str, BehaviorEvent] = {}
            for e in candidates:
                if e.signal_id not in best or e.confidence > best[e.signal_id].confidence:
                    best[e.signal_id] = e
            distinct = list(best.values())
            # independent channels: require members from >= 2 different groups
            groups = {e.group for e in distinct}
            if len(distinct) < det.get("threshold", 3) or len(groups) < 2:
                continue
            q_id = None
            if cs["id"] == "topic_linked_behavior_change":
                if not last_question or now_ms - last_question["t_ms"] > window_ms + 5000:
                    continue
                q_id = last_question["question_id"]
            elif last_question and now_ms - last_question["t_ms"] <= window_ms + 5000:
                q_id = last_question["question_id"]
            conf = round(min(0.97, sum(e.confidence for e in distinct) / len(distinct) * (0.85 + 0.03 * len(distinct))), 3)
            self._last_fired[cs["id"]] = now_ms
            out.append(ClusterResult(cs["id"], min(e.t_start_ms for e in distinct), max(e.t_end_ms for e in distinct), distinct, q_id, conf))
        return out
