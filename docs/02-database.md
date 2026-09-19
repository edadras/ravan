# ۰۲ — پایگاه داده (MySQL)

مهاجرت‌ها در `backend/database/migrations/2026_01_01_00000{1..5}_*.php`. داده‌های هویتی از داده‌های جلسه جدا هستند؛ سرویس تحلیل فقط `patient_profiles.pseudonym` را می‌بیند.

## هویت و درمانگران
| جدول | نکات |
|---|---|
| `users` | `role` (patient/clinician/admin)، `phone`، `locale`، `is_active`، soft delete |
| `patient_profiles` | `pseudonym` (UUID برای سرویس تحلیل)، تولد/جنسیت (اختیاری)، زبان، منطقه زمانی، تماس اضطراری رمزنگاری‌شده |
| `clinician_profiles` | عنوان، شماره/مرجع مجوز، انقضا، بیو، زبان‌ها، سابقه، هزینه، مدت جلسه، حالت‌ها، **`verification_status`** (pending/approved/rejected/suspended)، `verified_at`، پذیرش مراجع جدید، امتیاز |
| `specialties`, `clinician_specialty` | تخصص‌ها |
| `clinician_documents` | مدارک (دیسک خصوصی، sha256) |
| `clinician_verifications` | تاریخچه تصمیم‌های ادمین با `checked_items` |
| `clinician_schedules`, `clinician_time_off` | برنامه هفتگی (۰=شنبه) و مرخصی |

## نوبت و پرداخت
`appointments` (uuid، مراجع، درمانگر، بازه، حالت، وضعیت، هزینه، یادداشت، لغو) · `payments` (درگاه، مرجع، وضعیت) · `invoices`.

## جلسه
| جدول | نکات |
|---|---|
| `therapy_sessions` | uuid، نوبت، طرفین، حالت، وضعیت (scheduled/waiting/live/ended/cancelled)، `room_name`، زمان‌ها، **`analysis_enabled`**، `analysis_started_at/paused_at`، `analysis_session_ref`، زبان، خلاصه کیفیت و خط پایه |
| `session_participants` | ورود/خروج، اطلاعات دستگاه بدون شناسه |
| `consent_versions` | متن کامل هر نسخه رضایت به هر زبان + خلاصه نقطه‌ای |
| `session_consents` | نوع، نسخه، `granted_at`، `withdrawn_at`، هش IP/UA |
| `session_messages` | چت |
| `transcript_segments` | گوینده، بازه، متن، اطمینان، `is_question`، موضوع، ویژگی‌های زبانی |

## تحلیل رفتاری
| جدول | نکات |
|---|---|
| `behavior_signals` | کاتالوگ (seed از `catalog/signal_catalog.json`): گروه، سطح، متن دوزبانه، ویژگی‌ها، آشکارساز، دروازه‌ها، زمینه‌ها، دلیل و توضیح بالینی، برچسب‌های ممنوع، نسخه |
| `clinician_signal_preferences` | خاموش کردن سیگنال/گروه یا حداقل اطمینان برای هر درمانگر |
| `behavior_baselines` | میانه/سیگما/نرخ هر ویژگی به تفکیک حالت گوینده برای هر جلسه |
| `behavior_events` | uuid، جلسه، سیگنال، گروه، سطح، بازه، مشاهده (fa/en)، خط پایه/فعلی/دلتا/نسبت/z، واحد، اطمینان، کیفیت، زمینه (گوینده، موضوع، سؤال قبلی، اعضای خوشه)، زمینه‌های محتمل، دلیل/توضیح بالینی، اعضا، پیوند به بخش متن، **`clinician_status`** |
| `clinician_event_reviews` | «مرتبط/رد/یادداشت» + زمینه انتخاب‌شده |
| `clinical_notes` | یادداشت‌های خودِ درمانگر (هرگز تولید هوش مصنوعی) |
| `session_reports` | گزارش ساخت‌یافته سرویس تحلیل، پیش‌نویس، ارائه‌دهنده، موارد حذف‌شده توسط نگهبان، وضعیت |
| `report_versions` | نسخه‌های غیرقابل تغییر بازبینی (items: key/ai_text/clinician_text/status) |

## ممیزی و حریم خصوصی
`audit_logs` (هر اقدام مهم) · `access_logs` (هر خواندن متن/رویداد/گزارش با هدف) · `data_deletion_requests` (پس گرفتن رضایت → حذف زمان‌بندی‌شده) · `notifications`.

## نمونه رکورد `behavior_events`
```json
{
  "uuid": "…", "signal_id": "response_latency_increase", "group": "speech_prosody", "tier": "change",
  "t_start_ms": 754210, "t_end_ms": 758900,
  "observation_fa": "فاصله پایان سؤال تا شروع پاسخ طولانی‌تر از خط پایه است",
  "baseline_value": 1.4, "observed_value": 4.7, "delta": 3.3, "delta_ratio": 3.36, "z_score": 2.9, "unit": "s",
  "confidence": 0.86, "quality": {"audio_quality": 0.95, "asr_quality": 0.88, "network_rtt_ms": 120},
  "context": {"speaker": "patient_speaking", "topic_segment": "family",
              "preceding_question_text": "رابطه شما با خانواده چطور است؟", "seconds_after_question": 0.0},
  "possible_contexts": [{"key":"thinking","fa":"فکر کردن"}, {"key":"network_latency","fa":"تأخیر شبکه"}, …],
  "transcript_segment_id": 4211, "clinician_status": "unreviewed", "diagnostic_claim": null
}
```
