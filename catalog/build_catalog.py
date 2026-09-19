#!/usr/bin/env python3
"""Build the behaviour-signal catalog JSON artefacts.

Outputs (all in this directory):
  signal_catalog.json      – every signal with detector spec, gates, contexts, rationale (fa/en)
  feature_dictionary.json  – every primitive feature, landmark topology, blendshape and AU mapping
  parameter_space.json     – how the primitive features expand into millions of addressable parameters
  catalog_summary.md       – human-readable counts per group/tier

Run:  python3 build_catalog.py
"""

import json
import math
import os
import sys
from datetime import date

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import features as F  # noqa: E402
from contexts import CONTEXTS, TIERS  # noqa: E402
from translations_tr import (CLINICIAN_PROMPT_TR, CONTEXTS_TR, FORBIDDEN_TR, GATE_RULE_TR, GROUPS_TR,  # noqa: E402
                             PRINCIPLES_TR, PURPOSE_TR, TIERS_TR, UNSUPPORTED_TR)
import signals_face, signals_body, signals_speech, signals_multimodal  # noqa: E402

CATALOG_VERSION = "2.1.0"
LANGUAGES = ["fa", "en", "tr"]

GROUPS = {
    "face_head": ("Head orientation and motion", "جهت و حرکت سر"),
    "eyes_gaze": ("Eyes, gaze and blinking", "چشم‌ها، نگاه و پلک زدن"),
    "brow_forehead": ("Brows and forehead", "ابرو و پیشانی"),
    "mouth_lips_jaw": ("Mouth, lips and jaw", "دهان، لب و فک"),
    "facial_expression_dynamics": ("Facial expression dynamics", "پویایی حالت چهره"),
    "upper_body_posture": ("Upper-body posture", "وضعیت بالاتنه"),
    "shoulders_neck": ("Shoulders and neck", "شانه و گردن"),
    "hands_arms": ("Hands, arms and self-touch", "دست‌ها، بازوها و خودلمسی"),
    "gestures": ("Co-speech gestures", "ژست‌های همراه گفتار"),
    "lower_body": ("Lower body (when visible)", "پایین‌تنه (در صورت دیده شدن)"),
    "whole_body_motion": ("Whole-body motion", "حرکت کل بدن"),
    "speech_prosody": ("Speech rate, timing, loudness, pitch", "نرخ گفتار، زمان‌بندی، بلندی، زیر و بمی"),
    "voice_quality": ("Voice quality and non-verbal vocalisations", "کیفیت صدا و آواهای غیرکلامی"),
    "speech_fluency_language": ("Fluency and language use", "روانی گفتار و کاربرد زبان"),
    "turn_taking": ("Turn-taking and silence", "نوبت‌گیری و سکوت"),
    "interaction_synchrony": ("Patient–clinician synchrony (optional)", "هم‌زمانی بیمار و درمانگر (اختیاری)"),
    "multimodal_clusters": ("Multimodal clusters", "خوشه‌های چندوجهی"),
    "environment_technical": ("Environment and technical quality", "محیط و کیفیت فنی"),
    "operational": ("Operational / consent events", "رویدادهای عملیاتی و رضایت"),
}

PRINCIPLES = {
    "interpretation": {
        "en": "Every signal is an observation or hypothesis, never proof of a mental state or a diagnosis.",
        "fa": "هر سیگنال یک مشاهده یا فرضیه است، هرگز اثبات یک حالت روانی یا تشخیص نیست.",
        "tr": PRINCIPLES_TR["interpretation"]},
    "baseline_required": True,
    "baseline_window_s": 300,
    "baseline_min_quality_fraction": 0.6,
    "baseline_method": "robust (median / MAD) per feature, per speaker-state; re-estimated after camera_moved",
    "context_required": True,
    "multimodal_confirmation_preferred": True,
    "clinician_confirmation_required": True,
    "patient_facing_labels": False,
    "store_raw_video_default": False,
    "store_raw_audio_default": False,
    "derived_features_only": True,
    "network_latency_correction": True,
    "output_language_rule": {
        "en": "Describe what changed, by how much, relative to what baseline, with what quality, and list benign contexts first.",
        "fa": "بگویید چه چیزی، چقدر، نسبت به کدام خط پایه و با چه کیفیتی تغییر کرده و زمینه‌های بی‌خطر را اول فهرست کنید.",
        "tr": PRINCIPLES_TR["output_language_rule"]},
}

FORBIDDEN_INFERENCES = [
    {"id": "deception_or_lie_detection", "en": "Detecting lies or deception from any signal", "fa": "تشخیص دروغ یا فریب از هر سیگنالی"},
    {"id": "diagnosis_from_video_or_body_language", "en": "Any diagnosis from video, body language or voice", "fa": "هر تشخیصی از ویدئو، زبان بدن یا صدا"},
    {"id": "suicidality_from_pose_or_face_alone", "en": "Suicide/self-harm risk from face, body or voice (only explicit verbal content may be flagged)", "fa": "خطر خودکشی/خودآزاری از چهره، بدن یا صدا (فقط محتوای صریح کلامی پرچم‌گذاری می‌شود)"},
    {"id": "violence_risk_from_appearance_or_body_language_alone", "en": "Violence risk from appearance or body language", "fa": "خطر خشونت از ظاهر یا زبان بدن"},
    {"id": "emotion_certainty", "en": "Stating a specific emotion as fact (e.g. 'patient is anxious')", "fa": "بیان یک هیجان مشخص به‌عنوان واقعیت (مثلاً «بیمار مضطرب است»)"},
    {"id": "micro_expression_hidden_emotion", "en": "Claiming brief facial movements reveal hidden emotions", "fa": "ادعای اینکه حرکات کوتاه چهره هیجان پنهان را آشکار می‌کند"},
    {"id": "genuine_vs_fake_smile", "en": "Classifying smiles as genuine or fake", "fa": "دسته‌بندی لبخند به واقعی یا ساختگی"},
    {"id": "sexual_orientation", "en": "Sexual orientation", "fa": "گرایش جنسی"},
    {"id": "political_or_religious_beliefs", "en": "Political or religious beliefs", "fa": "باورهای سیاسی یا مذهبی"},
    {"id": "personality_disorder_from_visual_behavior", "en": "Personality traits or disorders from visual behaviour", "fa": "صفات یا اختلالات شخصیت از رفتار بصری"},
    {"id": "intelligence_or_competence", "en": "Intelligence or competence", "fa": "هوش یا شایستگی"},
    {"id": "substance_use_from_appearance_alone", "en": "Substance use from appearance alone", "fa": "مصرف مواد صرفاً از ظاهر"},
    {"id": "population_stereotype_comparison", "en": "Comparing a patient with population norms instead of their own baseline", "fa": "مقایسه بیمار با هنجار جمعیت به‌جای خط پایه خودش"},
    {"id": "automatic_clinical_record", "en": "Writing any AI output into the clinical record without clinician acceptance", "fa": "نوشتن هر خروجی هوش مصنوعی در پرونده بالینی بدون تأیید درمانگر"},
]

UNSUPPORTED_MEASUREMENTS = [
    {"id": "pupil_dilation", "reason_en": "Consumer webcams lack the resolution/IR to measure pupil size reliably; lighting dominates.", "reason_fa": "وب‌کم معمولی رزولوشن/مادون‌قرمز لازم برای اندازه‌گیری مردمک را ندارد؛ نور غالب است."},
    {"id": "heart_rate_rppg", "reason_en": "Remote photoplethysmography is highly sensitive to motion, compression and skin tone; excluded until validated per device.", "reason_fa": "rPPG به حرکت، فشرده‌سازی و رنگ پوست بسیار حساس است؛ تا اعتبارسنجی برای هر دستگاه حذف شده است."},
    {"id": "skin_conductance", "reason_en": "Not measurable from video.", "reason_fa": "از ویدئو قابل اندازه‌گیری نیست."},
    {"id": "facial_flushing", "reason_en": "Colour changes are confounded by white balance and compression.", "reason_fa": "تغییرات رنگ تحت تأثیر تراز سفیدی و فشرده‌سازی است."},
    {"id": "sweating", "reason_en": "Not reliably visible on compressed video.", "reason_fa": "روی ویدئوی فشرده به‌طور قابل اعتماد دیده نمی‌شود."},
    {"id": "emotion_classification", "reason_en": "Categorical emotion classifiers are deliberately not used; only AU/geometry descriptors.", "reason_fa": "طبقه‌بندهای هیجان عمداً استفاده نمی‌شوند؛ فقط توصیف‌گرهای واحد حرکتی/هندسه."},
]

EVENT_OUTPUT_TEMPLATE = {
    "id": "uuid",
    "session_id": "uuid",
    "signal_id": "response_latency_increase",
    "tier": "change",
    "t_start_ms": 754210,
    "t_end_ms": 758900,
    "observation": {"en": "Time from question end to answer start longer than baseline", "fa": "...", "tr": "..."},
    "baseline_value": 1.4,
    "observed_value": 4.7,
    "delta": 3.3,
    "delta_ratio": 3.36,
    "z_score": 2.9,
    "unit": "s",
    "confidence": 0.86,
    "quality": {"audio": 0.95, "asr": 0.88, "network_rtt_ms": 120},
    "context": {"speaker": "patient", "topic_segment": "family", "preceding_question_id": "uuid",
                "preceding_question_text": "رابطه شما با خانواده چطور است؟", "seconds_after_question": 0.0},
    "possible_contexts": ["thinking", "network_latency", "distraction", "question_type", "topic_related"],
    "clinician_prompt": {"en": "Consider whether this change is clinically relevant; ask for context if appropriate.",
                         "fa": "بررسی کنید آیا این تغییر از نظر بالینی مرتبط است؛ در صورت مناسب بودن، زمینه را بپرسید.",
                         "tr": CLINICIAN_PROMPT_TR},
    "member_events": [],
    "diagnostic_claim": None,
    "clinician_status": "unreviewed | relevant | dismissed | noted",
}


# Sustained detectors whose thresholds are expressed in robust z-units (relative to the
# person's own baseline) rather than raw feature units.
Z_SUSTAINED = {
    "slumped_posture_sustained", "shoulder_elevation_sustained", "eye_squint_sustained",
    "brow_lower_sustained", "lip_press_sustained", "jaw_clench_like_sustained",
    "lip_corner_depress_sustained", "brow_inner_raise_sustained", "expression_persistence_long",
    "hand_fidgeting", "whisper_like_low_energy_speech", "gaze_fixation_long",
}


def all_signals():
    out = []
    for mod in (signals_face, signals_body, signals_speech, signals_multimodal):
        out.extend(mod.SIGNALS)
    for s in out:
        det = s["detector"]
        if s["id"] in Z_SUSTAINED:
            det["comparison"] = "z_vs_session_baseline"
        # which window statistic a level_change / trend detector evaluates
        if det["type"] in ("level_change", "trend", "sustained"):
            det.setdefault("statistic", "std" if "variability" in s["id"] else "mean")
    return out


def validate(signals):
    ids = set()
    feature_ids = set(F.DERIVED_FEATURES)
    errors = []
    for s in signals:
        if s["id"] in ids:
            errors.append(f"duplicate id {s['id']}")
        ids.add(s["id"])
        if s["group"] not in GROUPS:
            errors.append(f"{s['id']}: unknown group {s['group']}")
        for f in s["features"]:
            if f not in feature_ids:
                errors.append(f"{s['id']}: unknown feature {f}")
    for s in signals:
        for m in s["detector"].get("member_signals", []):
            if m != "*" and m not in ids:
                errors.append(f"{s['id']}: unknown member signal {m}")
    for s in signals:
        for field in ("observation", "clinical_note", "tier_label"):
            for lang in LANGUAGES:
                if not s[field].get(lang):
                    errors.append(f"{s['id']}: {field} missing language {lang}")
        for c in s["possible_contexts"]:
            for lang in LANGUAGES:
                if not c.get(lang):
                    errors.append(f"{s['id']}: context {c['key']} missing language {lang}")
    for g in GROUPS:
        if g not in GROUPS_TR:
            errors.append(f"group {g} missing Turkish label")
    if errors:
        raise SystemExit("catalog validation failed:\n  " + "\n  ".join(errors))


def feature_dictionary():
    face_lm = [{"index": i, "role": next((k for k, v in F.FACE_KEYPOINTS.items() if v == i), None)} for i in range(F.FACE_MESH_LANDMARKS)]
    return {
        "schema_version": CATALOG_VERSION,
        "landmark_topologies": {
            "face_mesh": {"count": F.FACE_MESH_LANDMARKS, "source": "MediaPipe Face Landmarker (478 incl. iris)",
                          "keypoints": F.FACE_KEYPOINTS, "landmarks": face_lm},
            "pose": {"count": F.POSE_LANDMARKS, "source": "MediaPipe Pose Landmarker", "names": F.POSE_LANDMARK_NAMES},
            "hand": {"count": F.HAND_LANDMARKS, "per_hand": True, "hands": list(F.HANDS), "source": "MediaPipe Hand Landmarker", "names": F.HAND_LANDMARK_NAMES},
        },
        "blendshapes": F.BLENDSHAPES,
        "action_units": {k: {"name": v[0], "from_blendshapes": v[1]} for k, v in F.ACTION_UNITS.items()},
        "derived_features": {
            k: {"unit": v[0], "description": {"en": v[1], "fa": v[2]}, "source": v[3]} for k, v in F.DERIVED_FEATURES.items()
        },
        "axes": {
            "windows_s": F.WINDOWS_S,
            "statistics": F.STATISTICS,
            "comparisons": F.COMPARISONS,
            "speaker_states": F.SPEAKER_STATES,
        },
        "parameter_id_grammar": {
            "pattern": "<source>.<feature>[.<side>][.lmk:<i>[-<j>]].w<window_s>.<statistic>.<comparison>.<speaker_state>",
            "examples": [
                "face.head_yaw.w30.std.z_vs_session_baseline.any",
                "face.lmk_dist.lmk:33-263.w5.mean.ratio_vs_session_baseline.patient_speaking",
                "hands.hand_velocity.right.w10.max.delta_vs_previous_window.any",
                "audio.f0_std.w60.slope.trend_over_session.patient_speaking",
                "face.AU12.w2.max.absolute_threshold.patient_listening",
            ],
        },
    }


def parameter_space():
    n_face = F.FACE_MESH_LANDMARKS
    n_pose = F.POSE_LANDMARKS
    n_hand = F.HAND_LANDMARKS * len(F.HANDS)
    n_windows = len(F.WINDOWS_S)
    n_stats = len(F.STATISTICS)
    n_cmp = len(F.COMPARISONS)
    n_state = len(F.SPEAKER_STATES)
    combos = n_windows * n_stats * n_cmp * n_state

    primitives = {
        "face_landmark_coordinates": n_face * 3,
        "face_landmark_pairwise_distances": n_face * (n_face - 1) // 2,
        "face_landmark_velocities": n_face * 3,
        "pose_landmark_coordinates": n_pose * 4,  # x,y,z,visibility
        "pose_pairwise_distances": n_pose * (n_pose - 1) // 2,
        "pose_joint_angles": 24,
        "hand_landmark_coordinates": n_hand * 3,
        "hand_pairwise_distances_per_hand": len(F.HANDS) * (F.HAND_LANDMARKS * (F.HAND_LANDMARKS - 1) // 2),
        "hand_face_landmark_distances": n_hand * 12,  # each hand landmark to 12 face keypoints
        "blendshapes": len(F.BLENDSHAPES),
        "action_units": len(F.ACTION_UNITS),
        "derived_features": len(F.DERIVED_FEATURES),
        "audio_low_level_descriptors": 88,  # eGeMAPS-sized LLD set
        "mfcc_bands": 13 * 2,  # mean & delta
    }
    per_frame_total = sum(primitives.values())
    windowed_total = per_frame_total * combos
    return {
        "schema_version": CATALOG_VERSION,
        "explanation": {
            "en": ("The catalog defines a few hundred named signals. Underneath, every primitive measurement (landmark "
                   "coordinates, pairwise distances, blendshapes, action units, audio descriptors) is expanded across "
                   "time windows, statistics, comparison methods and speaker states. Each combination is an individually "
                   "addressable parameter with its own baseline, threshold and quality gate; the analysis service can "
                   "materialise any of them on demand by parameter id. Named signals are curated views over this space."),
            "fa": ("کاتالوگ چند صد سیگنال نام‌گذاری‌شده تعریف می‌کند. در زیر آن، هر اندازه‌گیری اولیه (مختصات نقاط، فاصله‌های جفتی، "
                   "بلندشیپ‌ها، واحدهای حرکتی، توصیف‌گرهای صوتی) روی پنجره‌های زمانی، آماره‌ها، روش‌های مقایسه و وضعیت گوینده گسترش می‌یابد. "
                   "هر ترکیب یک پارامتر مستقل با خط پایه، آستانه و دروازه کیفیت خودش است؛ سرویس تحلیل می‌تواند هر یک را با شناسه پارامتر "
                   "به‌صورت درخواستی محاسبه کند. سیگنال‌های نام‌گذاری‌شده نماهای گزینش‌شده روی این فضا هستند."),
        },
        "per_frame_primitives": primitives,
        "per_frame_total": per_frame_total,
        "axes": {"windows": n_windows, "statistics": n_stats, "comparisons": n_cmp, "speaker_states": n_state,
                 "combinations_per_primitive": combos},
        "addressable_parameters_total": windowed_total,
        "addressable_parameters_human": f"{windowed_total/1e6:.1f} million",
        "note": "Only parameters referenced by enabled signals are computed in real time; the rest are available for offline research with explicit consent.",
    }


def summary_md(signals):
    by_group, by_tier, by_det = {}, {}, {}
    for s in signals:
        by_group[s["group"]] = by_group.get(s["group"], 0) + 1
        by_tier[s["tier"]] = by_tier.get(s["tier"], 0) + 1
        by_det[s["detector"]["type"]] = by_det.get(s["detector"]["type"], 0) + 1
    ps = parameter_space()
    lines = [f"# Behaviour-signal catalog summary (v{CATALOG_VERSION}, {date.today()})", "",
             f"Total named signals: **{len(signals)}**", "",
             "## By group", "", "| group | fa | tr | signals |", "|---|---|---|---|"]
    for g, (en, fa) in GROUPS.items():
        lines.append(f"| {g} ({en}) | {fa} | {GROUPS_TR[g]} | {by_group.get(g, 0)} |")
    lines += ["", f"Languages: {', '.join(LANGUAGES)} (every observation, note, context, tier and group is validated in all three)."]
    lines += ["", "## By display tier", "", "| tier | signals |", "|---|---|"]
    for t, n in sorted(by_tier.items(), key=lambda x: -x[1]):
        lines.append(f"| {t} | {n} |")
    lines += ["", "## By detector type", "", "| detector | signals |", "|---|---|"]
    for t, n in sorted(by_det.items(), key=lambda x: -x[1]):
        lines.append(f"| {t} | {n} |")
    lines += ["", "## Parameter space", "",
              f"Per-frame primitive measurements: **{ps['per_frame_total']:,}**  ",
              f"Windows × statistics × comparisons × speaker states: **{ps['axes']['combinations_per_primitive']:,}**  ",
              f"Addressable parameters: **{ps['addressable_parameters_total']:,}** (~{ps['addressable_parameters_human']})", "",
              "See `parameter_space.json` for the breakdown and `feature_dictionary.json` for the id grammar."]
    return "\n".join(lines) + "\n"


def main():
    signals = all_signals()
    validate(signals)
    catalog = {
        "schema_version": CATALOG_VERSION,
        "generated": str(date.today()),
        "languages": LANGUAGES,
        "purpose": {
            "en": "Observational behavioural signals during consented tele-mental-health sessions. Not diagnostic and not a substitute for clinician judgment.",
            "fa": "سیگنال‌های رفتاری مشاهده‌ای در جلسات سلامت روان از راه دور با رضایت بیمار. تشخیصی نیست و جایگزین قضاوت درمانگر نیست.",
            "tr": PURPOSE_TR,
        },
        "principles": PRINCIPLES,
        "quality_gates_global": {
            "face": F.DERIVED_FEATURES["face_quality"][1],
            "pose": F.DERIVED_FEATURES["pose_quality"][1],
            "hands": F.DERIVED_FEATURES["hand_quality"][1],
            "audio": F.DERIVED_FEATURES["audio_quality"][1],
            "asr": F.DERIVED_FEATURES["asr_quality"][1],
            "rule": {"en": "A signal is never scored while any of its quality gates fails; the failing gate is emitted as a quality event instead.",
                     "fa": "سیگنال هرگز در حالی که یکی از دروازه‌های کیفیتش ناموفق است امتیازدهی نمی‌شود؛ به‌جای آن دروازه ناموفق به‌عنوان رویداد کیفیت صادر می‌شود.",
                     "tr": GATE_RULE_TR},
        },
        "groups": [{"id": g, "en": en, "fa": fa, "tr": GROUPS_TR[g], "signal_count": sum(1 for s in signals if s["group"] == g)} for g, (en, fa) in GROUPS.items()],
        "tiers": [{"id": t, "en": en, "fa": fa, "tr": TIERS_TR[t]} for t, (en, fa) in TIERS.items()],
        "contexts": [{"key": k, "en": en, "fa": fa, "tr": CONTEXTS_TR[k]} for k, (en, fa) in CONTEXTS.items()],
        "signals": signals,
        "forbidden_inferences": [dict(f, tr=FORBIDDEN_TR[f["id"]]) for f in FORBIDDEN_INFERENCES],
        "unsupported_measurements": [dict(u, reason_tr=UNSUPPORTED_TR[u["id"]]) for u in UNSUPPORTED_MEASUREMENTS],
        "event_output_template": EVENT_OUTPUT_TEMPLATE,
        "signal_count": len(signals),
    }
    with open(os.path.join(HERE, "signal_catalog.json"), "w", encoding="utf-8") as fh:
        json.dump(catalog, fh, ensure_ascii=False, indent=2)
    with open(os.path.join(HERE, "feature_dictionary.json"), "w", encoding="utf-8") as fh:
        json.dump(feature_dictionary(), fh, ensure_ascii=False, indent=2)
    with open(os.path.join(HERE, "parameter_space.json"), "w", encoding="utf-8") as fh:
        json.dump(parameter_space(), fh, ensure_ascii=False, indent=2)
    with open(os.path.join(HERE, "catalog_summary.md"), "w", encoding="utf-8") as fh:
        fh.write(summary_md(signals))
    print(f"ok: {len(signals)} signals, {len(F.DERIVED_FEATURES)} derived features, "
          f"{parameter_space()['addressable_parameters_total']:,} addressable parameters")


if __name__ == "__main__":
    main()
