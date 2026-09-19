"""Ravan behavioural-analysis service.

Consumes derived feature frames (never raw video/audio), builds a per-session
personal baseline, evaluates the signal catalog, fuses co-occurring changes into
clusters and emits clinician-facing observation events plus an end-of-session
report draft.  All outputs are observations; none is a diagnosis.
"""

__version__ = "0.1.0"
