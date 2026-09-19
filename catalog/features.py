"""Feature dictionary: every primitive measurement the pipeline can produce.

The catalog references these by id.  The parameter space (see
``build_catalog.py``) is the cartesian product of features x windows x
statistics x comparison methods, which is what turns a few hundred primitive
measurements into millions of concrete, individually addressable parameters.
"""

# ---------------------------------------------------------------------------
# Landmark topologies (MediaPipe conventions)
# ---------------------------------------------------------------------------
FACE_MESH_LANDMARKS = 478          # MediaPipe Face Mesh with iris refinement
POSE_LANDMARKS = 33                # MediaPipe Pose / BlazePose
HAND_LANDMARKS = 21                # per hand
HANDS = ("left", "right")

POSE_LANDMARK_NAMES = [
    "nose", "left_eye_inner", "left_eye", "left_eye_outer", "right_eye_inner", "right_eye",
    "right_eye_outer", "left_ear", "right_ear", "mouth_left", "mouth_right", "left_shoulder",
    "right_shoulder", "left_elbow", "right_elbow", "left_wrist", "right_wrist", "left_pinky",
    "right_pinky", "left_index", "right_index", "left_thumb", "right_thumb", "left_hip",
    "right_hip", "left_knee", "right_knee", "left_ankle", "right_ankle", "left_heel",
    "right_heel", "left_foot_index", "right_foot_index",
]

HAND_LANDMARK_NAMES = [
    "wrist", "thumb_cmc", "thumb_mcp", "thumb_ip", "thumb_tip", "index_mcp", "index_pip",
    "index_dip", "index_tip", "middle_mcp", "middle_pip", "middle_dip", "middle_tip",
    "ring_mcp", "ring_pip", "ring_dip", "ring_tip", "pinky_mcp", "pinky_pip", "pinky_dip",
    "pinky_tip",
]

# Key face-mesh indices used by derived features (MediaPipe canonical topology)
FACE_KEYPOINTS = {
    "nose_tip": 1, "chin": 152, "forehead": 10,
    "left_eye_outer": 33, "left_eye_inner": 133, "right_eye_inner": 362, "right_eye_outer": 263,
    "left_eye_top": 159, "left_eye_bottom": 145, "right_eye_top": 386, "right_eye_bottom": 374,
    "left_iris_center": 468, "right_iris_center": 473,
    "left_brow_inner": 107, "left_brow_mid": 105, "left_brow_outer": 70,
    "right_brow_inner": 336, "right_brow_mid": 334, "right_brow_outer": 300,
    "mouth_left": 61, "mouth_right": 291, "upper_lip_top": 13, "lower_lip_bottom": 14,
    "upper_lip_outer": 0, "lower_lip_outer": 17, "left_cheek": 50, "right_cheek": 280,
    "left_ear_tragion": 234, "right_ear_tragion": 454,
}

# ---------------------------------------------------------------------------
# ARKit-style blendshapes (MediaPipe Face Landmarker emits these 52 scores)
# ---------------------------------------------------------------------------
BLENDSHAPES = [
    "_neutral", "browDownLeft", "browDownRight", "browInnerUp", "browOuterUpLeft", "browOuterUpRight",
    "cheekPuff", "cheekSquintLeft", "cheekSquintRight", "eyeBlinkLeft", "eyeBlinkRight",
    "eyeLookDownLeft", "eyeLookDownRight", "eyeLookInLeft", "eyeLookInRight", "eyeLookOutLeft",
    "eyeLookOutRight", "eyeLookUpLeft", "eyeLookUpRight", "eyeSquintLeft", "eyeSquintRight",
    "eyeWideLeft", "eyeWideRight", "jawForward", "jawLeft", "jawOpen", "jawRight", "mouthClose",
    "mouthDimpleLeft", "mouthDimpleRight", "mouthFrownLeft", "mouthFrownRight", "mouthFunnel",
    "mouthLeft", "mouthLowerDownLeft", "mouthLowerDownRight", "mouthPressLeft", "mouthPressRight",
    "mouthPucker", "mouthRight", "mouthRollLower", "mouthRollUpper", "mouthShrugLower",
    "mouthShrugUpper", "mouthSmileLeft", "mouthSmileRight", "mouthStretchLeft", "mouthStretchRight",
    "mouthUpperUpLeft", "mouthUpperUpRight", "noseSneerLeft", "noseSneerRight", "tongueOut",
]

# ---------------------------------------------------------------------------
# FACS Action Units approximated from blendshapes / landmarks
# ---------------------------------------------------------------------------
ACTION_UNITS = {
    "AU1": ("Inner brow raiser", ["browInnerUp"]),
    "AU2": ("Outer brow raiser", ["browOuterUpLeft", "browOuterUpRight"]),
    "AU4": ("Brow lowerer", ["browDownLeft", "browDownRight"]),
    "AU5": ("Upper lid raiser", ["eyeWideLeft", "eyeWideRight"]),
    "AU6": ("Cheek raiser", ["cheekSquintLeft", "cheekSquintRight"]),
    "AU7": ("Lid tightener", ["eyeSquintLeft", "eyeSquintRight"]),
    "AU9": ("Nose wrinkler", ["noseSneerLeft", "noseSneerRight"]),
    "AU10": ("Upper lip raiser", ["mouthUpperUpLeft", "mouthUpperUpRight"]),
    "AU12": ("Lip corner puller", ["mouthSmileLeft", "mouthSmileRight"]),
    "AU14": ("Dimpler", ["mouthDimpleLeft", "mouthDimpleRight"]),
    "AU15": ("Lip corner depressor", ["mouthFrownLeft", "mouthFrownRight"]),
    "AU16": ("Lower lip depressor", ["mouthLowerDownLeft", "mouthLowerDownRight"]),
    "AU17": ("Chin raiser", ["mouthShrugLower"]),
    "AU18": ("Lip pucker", ["mouthPucker"]),
    "AU20": ("Lip stretcher", ["mouthStretchLeft", "mouthStretchRight"]),
    "AU22": ("Lip funneler", ["mouthFunnel"]),
    "AU23": ("Lip tightener", ["mouthPressLeft", "mouthPressRight"]),
    "AU24": ("Lip pressor", ["mouthPressLeft", "mouthPressRight", "mouthClose"]),
    "AU25": ("Lips part", ["jawOpen"]),
    "AU26": ("Jaw drop", ["jawOpen"]),
    "AU27": ("Mouth stretch", ["jawOpen"]),
    "AU28": ("Lip suck", ["mouthRollLower", "mouthRollUpper"]),
    "AU29": ("Jaw thrust", ["jawForward"]),
    "AU30": ("Jaw sideways", ["jawLeft", "jawRight"]),
    "AU34": ("Cheek puff", ["cheekPuff"]),
    "AU43": ("Eyes closed", ["eyeBlinkLeft", "eyeBlinkRight"]),
    "AU45": ("Blink", ["eyeBlinkLeft", "eyeBlinkRight"]),
    "AU19": ("Tongue show", ["tongueOut"]),
    "AU61": ("Eyes turn left", ["eyeLookOutLeft", "eyeLookInRight"]),
    "AU62": ("Eyes turn right", ["eyeLookInLeft", "eyeLookOutRight"]),
    "AU63": ("Eyes up", ["eyeLookUpLeft", "eyeLookUpRight"]),
    "AU64": ("Eyes down", ["eyeLookDownLeft", "eyeLookDownRight"]),
}

# ---------------------------------------------------------------------------
# Derived geometric / kinematic features (computed on client or worker)
# ---------------------------------------------------------------------------
# id -> (unit, description_en, description_fa, source)
DERIVED_FEATURES = {
    # head pose
    "head_yaw": ("deg", "Head rotation left/right", "چرخش سر چپ/راست", "face"),
    "head_pitch": ("deg", "Head rotation up/down", "چرخش سر بالا/پایین", "face"),
    "head_roll": ("deg", "Head lateral tilt", "کج شدن جانبی سر", "face"),
    "head_tx": ("norm", "Head horizontal position in frame", "موقعیت افقی سر در کادر", "face"),
    "head_ty": ("norm", "Head vertical position in frame", "موقعیت عمودی سر در کادر", "face"),
    "head_scale": ("norm", "Face size in frame (distance proxy)", "اندازه چهره در کادر (نماینده فاصله)", "face"),
    "head_angular_velocity": ("deg/s", "Angular speed of head", "سرعت زاویه‌ای سر", "face"),
    "head_motion_energy": ("norm/s", "Total head displacement rate", "نرخ جابه‌جایی کلی سر", "face"),
    # eyes / gaze
    "gaze_x": ("norm", "Horizontal gaze vector (iris relative to eye corners)", "بردار افقی نگاه", "face"),
    "gaze_y": ("norm", "Vertical gaze vector", "بردار عمودی نگاه", "face"),
    "gaze_on_screen": ("bool", "Gaze estimated within screen region", "نگاه در محدوده صفحه", "face"),
    "gaze_on_camera": ("bool", "Gaze estimated toward camera", "نگاه به سمت دوربین", "face"),
    "gaze_shift_event": ("event", "Saccade-like gaze shift", "جابه‌جایی ناگهانی نگاه", "face"),
    "gaze_fixation_duration": ("s", "Duration of current fixation", "مدت تثبیت نگاه فعلی", "face"),
    "ear_left": ("ratio", "Eye aspect ratio left", "نسبت ابعادی چشم چپ", "face"),
    "ear_right": ("ratio", "Eye aspect ratio right", "نسبت ابعادی چشم راست", "face"),
    "blink_event": ("event", "Blink detected (EAR dip)", "پلک زدن", "face"),
    "blink_duration": ("ms", "Duration of blink", "مدت پلک زدن", "face"),
    "eye_closure_duration": ("s", "Duration eyes closed", "مدت بسته بودن چشم", "face"),
    "eye_aperture_mean": ("ratio", "Mean eye opening vs baseline", "میانگین باز بودن چشم", "face"),
    "eye_asymmetry": ("ratio", "Left/right eye aperture asymmetry", "عدم تقارن باز بودن چشم‌ها", "face"),
    "iris_oscillation_hz": ("Hz", "Dominant frequency of iris micro-motion", "فرکانس غالب نوسان مردمک", "face"),
    # brows
    "brow_height_left": ("norm", "Left brow height relative to eye", "ارتفاع ابروی چپ", "face"),
    "brow_height_right": ("norm", "Right brow height relative to eye", "ارتفاع ابروی راست", "face"),
    "brow_distance": ("norm", "Inter-brow distance (furrow proxy)", "فاصله بین ابروها", "face"),
    "brow_asymmetry": ("norm", "Left/right brow height difference", "عدم تقارن ابروها", "face"),
    # mouth
    "mar": ("ratio", "Mouth aspect ratio", "نسبت ابعادی دهان", "face"),
    "mouth_width": ("norm", "Mouth corner distance", "فاصله گوشه‌های دهان", "face"),
    "lip_thickness": ("norm", "Visible lip thickness (compression proxy)", "ضخامت لب (نماینده فشردگی)", "face"),
    "mouth_corner_angle": ("deg", "Mouth corner elevation angle", "زاویه گوشه دهان", "face"),
    "smile_symmetry": ("ratio", "Left/right smile intensity ratio", "تقارن لبخند", "face"),
    "lip_oscillation_hz": ("Hz", "Dominant frequency of lip landmark micro-motion", "فرکانس نوسان لب", "face"),
    "jaw_lateral": ("norm", "Jaw lateral displacement", "جابه‌جایی جانبی فک", "face"),
    # face global
    "au_activity_total": ("score", "Sum of AU intensities", "مجموع شدت واحدهای حرکتی صورت", "face"),
    "au_activity_variance": ("score", "Variance of AU intensities over window", "واریانس شدت واحدهای حرکتی", "face"),
    "face_asymmetry_index": ("norm", "Global left/right facial asymmetry", "شاخص عدم تقارن چهره", "face"),
    "face_landmark_motion_energy": ("norm/s", "Total landmark displacement rate", "نرخ جابه‌جایی نقاط چهره", "face"),
    "expression_onset_latency": ("ms", "Time from stimulus to facial change", "تأخیر شروع تغییر چهره", "face"),
    # posture
    "torso_angle": ("deg", "Torso forward/backward inclination", "شیب جلو/عقب تنه", "pose"),
    "torso_lateral_angle": ("deg", "Torso lateral lean", "شیب جانبی تنه", "pose"),
    "torso_yaw": ("deg", "Shoulder-line rotation vs camera", "چرخش خط شانه نسبت به دوربین", "pose"),
    "neck_flexion": ("deg", "Neck flexion angle", "زاویه خم شدن گردن", "pose"),
    "shoulder_height_left": ("norm", "Left shoulder height vs hip", "ارتفاع شانه چپ", "pose"),
    "shoulder_height_right": ("norm", "Right shoulder height vs hip", "ارتفاع شانه راست", "pose"),
    "shoulder_asymmetry": ("norm", "Shoulder height difference", "اختلاف ارتفاع شانه‌ها", "pose"),
    "shoulder_neck_distance": ("norm", "Shoulder-to-ear distance (elevation proxy)", "فاصله شانه تا گوش", "pose"),
    "shoulder_width_ratio": ("ratio", "Shoulder width vs baseline (rounding proxy)", "نسبت عرض شانه (گرد شدن)", "pose"),
    "body_center_x": ("norm", "Body horizontal center", "مرکز افقی بدن", "pose"),
    "body_center_y": ("norm", "Body vertical center", "مرکز عمودی بدن", "pose"),
    "body_scale": ("norm", "Body size in frame (distance proxy)", "اندازه بدن در کادر", "pose"),
    "pose_delta": ("norm/s", "Frame-to-frame pose displacement", "جابه‌جایی وضعیت بین فریم‌ها", "pose"),
    "pose_change_event": ("event", "Meaningful posture change", "تغییر معنادار وضعیت", "pose"),
    "torso_oscillation_hz": ("Hz", "Dominant torso sway frequency", "فرکانس نوسان تنه", "pose"),
    "motion_energy_total": ("norm/s", "Whole-body motion energy", "انرژی حرکتی کل بدن", "pose"),
    "motion_symmetry": ("ratio", "Left/right limb motion ratio", "تقارن حرکت اندام‌ها", "pose"),
    "stillness_duration": ("s", "Time since last meaningful movement", "مدت سکون", "pose"),
    "sudden_motion_event": ("event", "High-acceleration body movement", "حرکت ناگهانی بدن", "pose"),
    # arms / hands
    "arms_crossed_score": ("score", "Wrist-elbow-shoulder geometry crossing score", "امتیاز جمع بودن دست‌ها", "pose"),
    "hand_face_distance_left": ("norm", "Left hand to face distance", "فاصله دست چپ تا صورت", "hands"),
    "hand_face_distance_right": ("norm", "Right hand to face distance", "فاصله دست راست تا صورت", "hands"),
    "hand_region_contact": ("cat", "Body region touched by hand (mouth, nose, eye, forehead, hair, neck, ear, chest, abdomen, arm, hand, none)", "ناحیه‌ای از بدن که دست لمس می‌کند", "hands"),
    "hand_contact_event": ("event", "Hand-body contact onset", "شروع تماس دست با بدن", "hands"),
    "hand_contact_duration": ("s", "Duration of current contact", "مدت تماس فعلی", "hands"),
    "hand_velocity_left": ("norm/s", "Left hand speed", "سرعت دست چپ", "hands"),
    "hand_velocity_right": ("norm/s", "Right hand speed", "سرعت دست راست", "hands"),
    "hand_acceleration": ("norm/s2", "Hand acceleration magnitude", "شتاب دست", "hands"),
    "hand_oscillation_hz": ("Hz", "Dominant frequency of hand micro-motion", "فرکانس نوسان دست", "hands"),
    "hand_oscillation_amplitude": ("norm", "Amplitude of hand micro-motion", "دامنه نوسان دست", "hands"),
    "finger_motion_energy": ("norm/s", "Finger landmark motion energy", "انرژی حرکتی انگشتان", "hands"),
    "finger_tap_event": ("event", "Finger tap-like event", "ضربه انگشت", "hands"),
    "hands_visible": ("bool", "Hands in frame", "دیده شدن دست‌ها در کادر", "hands"),
    "hands_clasped_score": ("score", "Two-hand proximity/interlock score", "امتیاز قلاب شدن دست‌ها", "hands"),
    "hand_object_score": ("score", "Object-in-hand likelihood", "احتمال وجود شیء در دست", "hands"),
    "gesture_event": ("event", "Conversational gesture onset", "شروع ژست گفتاری", "hands"),
    "gesture_amplitude": ("norm", "Gesture spatial extent", "دامنه فضایی ژست", "hands"),
    "gesture_speed": ("norm/s", "Gesture peak speed", "اوج سرعت ژست", "hands"),
    "gesture_speech_alignment": ("ratio", "Fraction of gestures co-occurring with speech", "نسبت ژست‌های هم‌زمان با گفتار", "hands"),
    "palm_orientation": ("cat", "Palm up / down / toward camera", "جهت کف دست", "hands"),
    "pointing_score": ("score", "Index-finger extension pointing score", "امتیاز اشاره با انگشت", "hands"),
    "hand_covering_face_score": ("score", "Fraction of face occluded by hands", "نسبت پوشیدگی صورت با دست", "hands"),
    "head_support_score": ("score", "Head resting on hand score", "امتیاز تکیه دادن سر روی دست", "hands"),
    # lower body
    "lower_body_visible": ("bool", "Hips/knees/ankles in frame", "دیده شدن پایین‌تنه", "pose"),
    "knee_oscillation_hz": ("Hz", "Knee vertical oscillation frequency", "فرکانس نوسان زانو", "pose"),
    "ankle_oscillation_hz": ("Hz", "Ankle oscillation frequency", "فرکانس نوسان مچ پا", "pose"),
    "leg_cross_state": ("cat", "Leg crossing state", "وضعیت روی هم انداختن پا", "pose"),
    "hip_shift_event": ("event", "Whole-body seat shift", "جابه‌جایی روی صندلی", "pose"),
    "standing_event": ("event", "Stood up / left seat", "بلند شدن از صندلی", "pose"),
    # audio: prosody
    "f0_mean": ("Hz", "Fundamental frequency mean", "میانگین فرکانس پایه", "audio"),
    "f0_std": ("Hz", "F0 standard deviation", "انحراف معیار فرکانس پایه", "audio"),
    "f0_range": ("semitones", "F0 range (p95-p5)", "دامنه فرکانس پایه", "audio"),
    "f0_slope_final": ("st/s", "F0 slope at utterance end", "شیب فرکانس پایه در انتهای جمله", "audio"),
    "f0_modulation_hz": ("Hz", "Low-frequency F0 modulation (tremor band 4-8 Hz)", "مدولاسیون فرکانس پایه", "audio"),
    "loudness_rms_db": ("dB", "Relative RMS loudness", "بلندی نسبی", "audio"),
    "loudness_std": ("dB", "Loudness variability", "تغییرپذیری بلندی", "audio"),
    "loudness_peak_rate": ("/min", "Loudness peaks per minute", "تعداد اوج بلندی در دقیقه", "audio"),
    "speech_rate_wpm": ("wpm", "Words per minute (from ASR)", "کلمه در دقیقه", "asr"),
    "articulation_rate_sps": ("syl/s", "Syllables per second of voiced time", "هجا در ثانیه", "audio"),
    "syllable_duration_mean": ("ms", "Mean syllable duration", "میانگین مدت هجا", "audio"),
    "tempo_variability": ("ratio", "Coefficient of variation of syllable rate", "تغییرپذیری تمپو", "audio"),
    "voiced_ratio": ("ratio", "Voiced time / speaking time", "نسبت زمان واکدار", "audio"),
    "pause_count": ("/min", "Pauses per minute", "تعداد مکث در دقیقه", "audio"),
    "pause_duration_mean": ("ms", "Mean pause duration", "میانگین مدت مکث", "audio"),
    "pause_duration_max": ("ms", "Longest pause in window", "طولانی‌ترین مکث", "audio"),
    "filled_pause_rate": ("/min", "Filled pauses (um, uh, اِ، اوم) per minute", "مکث‌های پرشده در دقیقه", "asr"),
    "response_latency_ms": ("ms", "Question end to answer start (network-corrected)", "تأخیر پاسخ (با تصحیح تأخیر شبکه)", "turn"),
    "utterance_duration": ("s", "Duration of a speaking turn", "مدت نوبت گفتار", "turn"),
    "speech_energy_slope": ("dB/min", "Loudness trend over window", "روند بلندی", "audio"),
    # audio: voice quality
    "jitter": ("%", "Cycle-to-cycle F0 perturbation", "لرزش فرکانسی", "audio"),
    "shimmer": ("%", "Cycle-to-cycle amplitude perturbation", "لرزش دامنه", "audio"),
    "hnr": ("dB", "Harmonics-to-noise ratio", "نسبت هارمونیک به نویز", "audio"),
    "spectral_tilt": ("dB/oct", "Spectral slope (tense/breathy proxy)", "شیب طیفی", "audio"),
    "alpha_ratio": ("dB", "Energy ratio 50-1k / 1k-5k Hz", "نسبت آلفا", "audio"),
    "hammarberg_index": ("dB", "Hammarberg index", "شاخص هامربرگ", "audio"),
    "cpp": ("dB", "Cepstral peak prominence", "برجستگی قله کپسترال", "audio"),
    "creak_ratio": ("ratio", "Fraction of voiced frames with creaky phonation", "نسبت صدای خش‌دار/فرای", "audio"),
    "breathiness_index": ("score", "Breathiness estimate (H1-H2, HNR)", "شاخص نفس‌آلود بودن", "audio"),
    "mfcc_1_13": ("vec", "MFCC 1-13 means/stds", "ضرایب MFCC", "audio"),
    "spectral_flux": ("score", "Spectral change rate", "نرخ تغییر طیفی", "audio"),
    "spectral_centroid": ("Hz", "Spectral centroid", "مرکز ثقل طیفی", "audio"),
    "formant_f1": ("Hz", "First formant mean", "فرمانت اول", "audio"),
    "formant_f2": ("Hz", "Second formant mean", "فرمانت دوم", "audio"),
    "formant_bandwidth": ("Hz", "Formant bandwidth", "پهنای باند فرمانت", "audio"),
    "voice_break_event": ("event", "Voicing break within utterance", "شکست صدا", "audio"),
    "breath_event": ("event", "Audible inhalation/exhalation", "نفس شنیدنی", "audio"),
    "breath_rate": ("/min", "Audible breaths per minute", "تعداد نفس در دقیقه", "audio"),
    "sigh_event": ("event", "Sigh-like exhalation", "آه کشیدن", "audio"),
    "nonverbal_vocal_event": ("cat", "laugh / cough / throat-clear / sniff / sob-like / yawn", "رویداد صوتی غیرکلامی", "audio"),
    "audio_snr_db": ("dB", "Signal-to-noise ratio", "نسبت سیگنال به نویز", "audio"),
    "audio_clipping_ratio": ("ratio", "Clipped samples ratio", "نسبت اشباع صدا", "audio"),
    "background_noise_event": ("event", "Transient background sound", "صدای پس‌زمینه گذرا", "audio"),
    "background_voice_event": ("event", "Second voice detected", "صدای فرد دوم", "audio"),
    # ASR / language
    "asr_confidence": ("score", "ASR segment confidence", "اطمینان تشخیص گفتار", "asr"),
    "word_count": ("count", "Words in turn", "تعداد کلمات نوبت", "asr"),
    "utterance_word_count_mean": ("count", "Mean words per utterance", "میانگین کلمات هر گفته", "asr"),
    "type_token_ratio": ("ratio", "Lexical diversity", "تنوع واژگانی", "asr"),
    "repetition_rate": ("/min", "Word/phrase repetitions per minute", "تکرار واژه/عبارت در دقیقه", "asr"),
    "self_correction_rate": ("/min", "Self-corrections per minute", "خوداصلاحی در دقیقه", "asr"),
    "incomplete_utterance_rate": ("ratio", "Utterances abandoned mid-sentence", "نسبت گفته‌های ناتمام", "asr"),
    "disfluency_rate": ("/100w", "Stutter-like disfluencies per 100 words", "ناروانی در صد کلمه", "asr"),
    "first_person_ratio": ("ratio", "First-person singular pronoun ratio", "نسبت ضمیر اول‌شخص مفرد", "asr"),
    "negative_lexicon_ratio": ("ratio", "Negative-valence lexicon hits / words", "نسبت واژگان با بار منفی", "asr"),
    "positive_lexicon_ratio": ("ratio", "Positive-valence lexicon hits / words", "نسبت واژگان با بار مثبت", "asr"),
    "absolutist_ratio": ("ratio", "Absolutist words (always, never, همیشه، هرگز)", "نسبت واژگان مطلق‌گرا", "asr"),
    "hedge_ratio": ("ratio", "Hedging words (maybe, I guess, شاید)", "نسبت واژگان تردیدآمیز", "asr"),
    "certainty_ratio": ("ratio", "Certainty markers", "نسبت نشانگرهای قطعیت", "asr"),
    "past_tense_ratio": ("ratio", "Past-tense verb ratio", "نسبت افعال گذشته", "asr"),
    "future_tense_ratio": ("ratio", "Future-tense ratio", "نسبت افعال آینده", "asr"),
    "sentence_complexity": ("score", "Mean clause depth / sentence length", "پیچیدگی جمله", "asr"),
    "minimal_response_flag": ("bool", "Answer is one/two words to open question", "پاسخ حداقلی به سؤال باز", "asr"),
    "deflection_flag": ("bool", "Answer changes subject (LLM-judged, low confidence)", "تغییر موضوع در پاسخ", "llm"),
    "risk_lexicon_hit": ("event", "Explicit safety-relevant phrase in transcript", "عبارت صریح مرتبط با ایمنی", "asr"),
    "topic_segment_id": ("cat", "Current topic segment (LLM-derived)", "بخش موضوعی فعلی", "llm"),
    "question_event": ("event", "Clinician question end", "پایان سؤال درمانگر", "turn"),
    # turn taking
    "speaker": ("cat", "Diarized speaker", "گوینده", "turn"),
    "turn_gap_ms": ("ms", "Gap between turns", "فاصله بین نوبت‌ها", "turn"),
    "overlap_duration": ("ms", "Overlapping speech duration", "مدت هم‌پوشانی گفتار", "turn"),
    "silence_duration": ("s", "Current silence duration", "مدت سکوت فعلی", "turn"),
    "backchannel_event": ("event", "Short listener vocalization", "بازخورد کوتاه شنونده", "turn"),
    "speech_share_patient": ("ratio", "Patient speaking time share", "سهم گفتار بیمار", "turn"),
    "patient_initiated_turn": ("event", "Patient starts a turn without a question", "شروع نوبت توسط بیمار بدون سؤال", "turn"),
    # synchrony (requires clinician features, optional)
    "posture_mirroring_score": ("score", "Cross-correlation of torso angles", "امتیاز آینه‌ای شدن وضعیت", "dyad"),
    "nod_sync_score": ("score", "Nods aligned with clinician speech", "هم‌زمانی سر تکان دادن با گفتار درمانگر", "dyad"),
    "prosodic_convergence": ("score", "Pitch/rate convergence over time", "همگرایی عروضی", "dyad"),
    "smile_reciprocity": ("score", "Smiles within 2 s of clinician smile", "متقابل بودن لبخند", "dyad"),
    # quality
    "face_quality": ("score", "Face landmark confidence", "کیفیت نقاط چهره", "quality"),
    "pose_quality": ("score", "Pose landmark confidence", "کیفیت نقاط بدن", "quality"),
    "hand_quality": ("score", "Hand landmark confidence", "کیفیت نقاط دست", "quality"),
    "audio_quality": ("score", "Audio quality composite", "کیفیت صدا", "quality"),
    "asr_quality": ("score", "Transcript confidence", "کیفیت رونویسی", "quality"),
    "face_in_frame_ratio": ("ratio", "Fraction of face inside frame", "نسبت چهره داخل کادر", "quality"),
    "face_size_px": ("px", "Face bounding box size", "اندازه چهره به پیکسل", "quality"),
    "illumination": ("score", "Face region brightness", "روشنایی ناحیه چهره", "quality"),
    "motion_blur": ("score", "Blur estimate", "تخمین تاری حرکتی", "quality"),
    "fps": ("Hz", "Effective analysis frame rate", "نرخ فریم مؤثر", "quality"),
    "faces_detected": ("count", "Number of faces", "تعداد چهره‌ها", "quality"),
    "persons_detected": ("count", "Number of persons", "تعداد افراد", "quality"),
    "network_rtt_ms": ("ms", "Round-trip time", "زمان رفت و برگشت شبکه", "quality"),
    "packet_loss": ("ratio", "Packet loss", "افت بسته", "quality"),
    "camera_moved_event": ("event", "Camera/device repositioned", "جابه‌جایی دوربین", "quality"),
    "mask_detected": ("bool", "Face covering detected", "ماسک تشخیص داده شد", "quality"),
    "glasses_detected": ("bool", "Glasses detected", "عینک تشخیص داده شد", "quality"),
}

# ---------------------------------------------------------------------------
# Parameter-space axes
# ---------------------------------------------------------------------------
WINDOWS_S = [2, 5, 10, 15, 30, 60, 120, 300, 600]
STATISTICS = ["mean", "median", "std", "min", "max", "p10", "p90", "slope", "count", "rate",
              "entropy", "dominant_freq", "spectral_power", "autocorr_lag1", "range"]
COMPARISONS = ["z_vs_session_baseline", "ratio_vs_session_baseline", "delta_vs_previous_window",
               "absolute_threshold", "trend_over_session", "vs_topic_segment_mean", "vs_own_speaking_vs_listening"]
SPEAKER_STATES = ["patient_speaking", "patient_listening", "silence", "any"]
