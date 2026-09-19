# Ravan Flutter web app

Patient, clinician and admin front-end for the Ravan platform. Trilingual: Persian (RTL), English and Turkish; the language switcher in every app bar persists the choice, sets text direction, sends `Accept-Language` to the API and selects the matching consent texts and catalog strings (`lib/core/l10n.dart`).

```
flutter pub get
flutter run -d chrome \
  --dart-define=RAVAN_API_URL=http://localhost:8000/api \
  --dart-define=RAVAN_WS_URL=ws://localhost:8080 \
  --dart-define=RAVAN_WS_KEY=ravan
```

Key parts

| Path | Purpose |
|---|---|
| `web/vision_worker.js` | On-device MediaPipe face/pose/hand landmarks → derived features (see `catalog/feature_dictionary.json`). Streams numbers only to the analysis service. |
| `web/audio_worker.js` | On-device loudness / pitch / pause features and coarse speaker state. |
| `lib/features/session/consent_dialog.dart` | Two separate consents shown before the camera is used. |
| `lib/features/session/patient_session_screen.dart` | Call + chat + analysis switch + consent withdrawal. Patients never see behaviour events. |
| `lib/features/session/doctor_console_screen.dart` | Large patient video, live observation timeline (tier filters, confidence slider), transcript, notes, clinician marks and topic tags. |
| `lib/features/session/widgets/event_card.dart` | Observation card: what changed, how much, vs. which baseline, at what quality, benign contexts first, rationale, Mark relevant / Dismiss / Note. |
| `lib/features/report/report_screen.dart` | End-of-session report with Accept / Edit / Reject per AI-drafted item; only the clinician's text goes to the record. |
| `lib/features/admin/admin_verification_screen.dart` | Admin verification of clinician credentials. |

The Flutter SDK is not available in the CI container used to generate this scaffold, so the Dart code has not been compiled there; run `flutter analyze` locally after `flutter pub get`.
