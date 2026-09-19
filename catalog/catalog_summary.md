# Behaviour-signal catalog summary (v2.1.0, 2026-09-19)

Total named signals: **279**

## By group

| group | fa | tr | signals |
|---|---|---|---|
| face_head (Head orientation and motion) | جهت و حرکت سر | Baş yönelimi ve hareketi | 16 |
| eyes_gaze (Eyes, gaze and blinking) | چشم‌ها، نگاه و پلک زدن | Gözler, bakış ve göz kırpma | 22 |
| brow_forehead (Brows and forehead) | ابرو و پیشانی | Kaşlar ve alın | 7 |
| mouth_lips_jaw (Mouth, lips and jaw) | دهان، لب و فک | Ağız, dudaklar ve çene | 24 |
| facial_expression_dynamics (Facial expression dynamics) | پویایی حالت چهره | Yüz ifadesi dinamikleri | 10 |
| upper_body_posture (Upper-body posture) | وضعیت بالاتنه | Üst beden duruşu | 14 |
| shoulders_neck (Shoulders and neck) | شانه و گردن | Omuzlar ve boyun | 8 |
| hands_arms (Hands, arms and self-touch) | دست‌ها، بازوها و خودلمسی | Eller, kollar ve kendine dokunma | 34 |
| gestures (Co-speech gestures) | ژست‌های همراه گفتار | Konuşmaya eşlik eden jestler | 8 |
| lower_body (Lower body (when visible)) | پایین‌تنه (در صورت دیده شدن) | Alt beden (görünürse) | 8 |
| whole_body_motion (Whole-body motion) | حرکت کل بدن | Tüm beden hareketi | 10 |
| speech_prosody (Speech rate, timing, loudness, pitch) | نرخ گفتار، زمان‌بندی، بلندی، زیر و بمی | Konuşma hızı, zamanlama, ses şiddeti, perde | 22 |
| voice_quality (Voice quality and non-verbal vocalisations) | کیفیت صدا و آواهای غیرکلامی | Ses kalitesi ve sözel olmayan seslendirmeler | 24 |
| speech_fluency_language (Fluency and language use) | روانی گفتار و کاربرد زبان | Akıcılık ve dil kullanımı | 19 |
| turn_taking (Turn-taking and silence) | نوبت‌گیری و سکوت | Söz sırası ve sessizlik | 9 |
| interaction_synchrony (Patient–clinician synchrony (optional)) | هم‌زمانی بیمار و درمانگر (اختیاری) | Hasta–klinisyen eşzamanlılığı (isteğe bağlı) | 4 |
| multimodal_clusters (Multimodal clusters) | خوشه‌های چندوجهی | Çok kanallı kümeler | 10 |
| environment_technical (Environment and technical quality) | محیط و کیفیت فنی | Ortam ve teknik kalite | 26 |
| operational (Operational / consent events) | رویدادهای عملیاتی و رضایت | Operasyonel / onay olayları | 4 |

Languages: fa, en, tr (every observation, note, context, tier and group is validated in all three).

## By display tier

| tier | signals |
|---|---|
| change | 114 |
| observation | 107 |
| quality | 33 |
| medical | 9 |
| cluster | 9 |
| content | 6 |
| safety | 1 |

## By detector type

| detector | signals |
|---|---|
| level_change | 81 |
| event | 63 |
| sustained | 45 |
| rate_change | 40 |
| quality | 23 |
| periodicity | 13 |
| cluster | 9 |
| trend | 3 |
| content | 2 |

## Parameter space

Per-frame primitive measurements: **118,985**  
Windows × statistics × comparisons × speaker states: **3,780**  
Addressable parameters: **449,763,300** (~449.8 million)

See `parameter_space.json` for the breakdown and `feature_dictionary.json` for the id grammar.
