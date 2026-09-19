# ۰۳ — API

پایه: `/api`. احراز هویت: `Authorization: Bearer <sanctum token>`. پاسخ‌ها JSON.

## عمومی
| متد | مسیر | توضیح |
|---|---|---|
| POST | `auth/register` | ثبت‌نام مراجع (درمانگران فقط توسط ادمین ساخته می‌شوند) |
| POST | `auth/login` | ورود → `{user, token}` |
| GET | `clinicians` | فهرست **فقط درمانگران تأییدشده**؛ فیلتر `specialty`, `language`, `mode`, `max_fee`, `accepting` |
| GET | `clinicians/specialties` | تخصص‌ها |
| GET | `clinicians/{id}` | پروفایل (۴۰۴ اگر تأیید نشده) |
| GET | `clinicians/{id}/availability?days=14` | زمان‌های آزاد از برنامه هفتگی − نوبت‌ها − مرخصی |
| GET | `consents/texts?locale=fa` | متن نسخه فعلی رضایت‌ها |
| POST | `webhooks/analysis/events` | ورودی امضاشده سرویس تحلیل (`X-Ravan-Signature` = HMAC-SHA256 بدنه) |

## کاربر واردشده
| متد | مسیر | نقش | توضیح |
|---|---|---|---|
| GET | `auth/me` | همه | |
| POST | `auth/logout` | همه | |
| GET/POST | `appointments` | مراجع (POST) | رزرو؛ همزمان پوسته جلسه ساخته می‌شود |
| POST | `appointments/{id}/confirm` | درمانگر | |
| POST | `appointments/{id}/cancel` | طرفین | |
| GET | `sessions/{uuid}` | طرفین | وضعیت، رضایت‌ها، `analysis_allowed` |
| POST | `sessions/{uuid}/join` | طرفین | توکن SFU، کانال WebSocket، و برای مراجع مشخصات WS سرویس تحلیل |
| POST | `sessions/{uuid}/end` | طرفین | پایان + دریافت گزارش از سرویس تحلیل |
| POST | `sessions/{uuid}/analysis/start` | طرفین | نیازمند هر دو رضایت؛ شروع/ادامه تحلیل |
| POST | `sessions/{uuid}/analysis/pause` | طرفین | توقف بدون قطع جلسه |
| POST | `sessions/{uuid}/analysis/mark` | درمانگر | علامت‌گذاری لحظه |
| POST | `sessions/{uuid}/analysis/topic` | درمانگر | برچسب موضوع فعلی |
| POST | `sessions/{uuid}/consents` | **فقط مراجع** | `{types:[video_call, behavior_analysis, transcription]}` |
| POST | `sessions/{uuid}/consents/withdraw` | فقط مراجع | توقف فوری + زمان‌بندی حذف داده‌های مشتق |
| GET/POST | `sessions/{uuid}/messages` | طرفین | چت |
| GET/POST | `sessions/{uuid}/transcript` | طرفین | بخش‌های رونویسی (POST نیازمند رضایت transcription) |
| GET | `sessions/{uuid}/events` | **فقط درمانگر** | فیلتر `since_ms`, `tier`, `group`, `min_confidence`, `status` |
| GET | `events/{uuid}` | درمانگر | رویداد + اعضای خوشه + متن اطراف |
| POST | `events/{uuid}/review` | درمانگر | `{status: relevant|dismissed|noted, note?, selected_context?}` |
| GET/POST | `sessions/{uuid}/notes` | درمانگر | یادداشت بالینی |
| GET | `sessions/{uuid}/report` | درمانگر | گزارش + نسخه‌ها |
| POST | `sessions/{uuid}/report/review` | درمانگر | `{items:[{key, ai_text, clinician_text, status: accepted|edited|rejected}], summary, finalize}` |
| GET | `catalog/signals[?group=&tier=]`, `catalog/signals/{id}` | همه | کاتالوگ برای راهنما و فیلتر |

## ادمین (`admin/*`, نقش admin)
`GET clinicians?status=` · `POST clinicians` (ساخت حساب + پروفایل) · `POST clinicians/{id}/documents` · `GET clinicians/{id}/documents/{docId}` · `POST clinicians/{id}/decision` (`approved|rejected|suspended|info_requested`, `checked_items`) · `POST specialties`.

## کانال‌های WebSocket (Reverb/Pusher)
- `private-session.{uuid}.clinician`: `behavior.event`, `transcript.segment`, `chat.message`, `session.status`
- `private-session.{uuid}.patient`: `transcript.segment`, `chat.message`, `session.status` (**هرگز** رویداد رفتاری)

## سرویس تحلیل (داخلی، `RAVAN_ANALYSIS_TOKEN`)
`POST /sessions` · `POST /sessions/{id}/frames` · `WS /ws/sessions/{id}?token=` (پیام‌های `frame|transcript|question|control`) · `POST /sessions/{id}/transcript` · `POST /sessions/{id}/question` · `POST /sessions/{id}/control` · `GET /sessions/{id}/events` · `GET /sessions/{id}/baseline` · `POST /sessions/{id}/finish` · `GET /catalog` · `GET /healthz`.
