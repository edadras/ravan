import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'l10n_extra.dart';

/// Supported UI languages. fa is RTL; en and tr are LTR.
const supportedLangs = ['fa', 'en', 'tr'];
const langNames = {'fa': 'فارسی', 'en': 'English', 'tr': 'Türkçe'};

class LocaleStore extends ChangeNotifier {
  String code = 'fa';

  Locale get locale => Locale(code);
  TextDirection get direction => code == 'fa' ? TextDirection.rtl : TextDirection.ltr;

  Future<void> restore() async {
    try {
      final saved = (await SharedPreferences.getInstance()).getString('lang');
      if (saved != null && supportedLangs.contains(saved)) code = saved;
    } catch (_) {}
    notifyListeners();
  }

  Future<void> set(String c) async {
    if (!supportedLangs.contains(c)) return;
    code = c;
    notifyListeners();
    try {
      (await SharedPreferences.getInstance()).setString('lang', c);
    } catch (_) {}
  }
}

class LocaleScope extends InheritedNotifier<LocaleStore> {
  const LocaleScope({super.key, required LocaleStore store, required super.child}) : super(notifier: store);

  static LocaleStore of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<LocaleScope>()!.notifier!;
}

extension L10nX on BuildContext {
  String get lang => LocaleScope.of(this).code;

  /// Translate a key; `{name}` placeholders are replaced from [args].
  String t(String key, [Map<String, Object?> args = const {}]) {
    var s = _strings[lang]?[key] ?? stringsExtra[lang]?[key] ?? _strings['en']?[key] ?? stringsExtra['en']?[key] ?? key;
    args.forEach((k, v) => s = s.replaceAll('{$k}', '$v'));
    return s;
  }

  /// Pick a value from a localized map like {"fa": ..., "en": ..., "tr": ...}.
  String pick(Map<String, dynamic>? m) => (m?[lang] ?? m?['en'] ?? m?.values.firstOrNull ?? '').toString();
}

const _strings = <String, Map<String, String>>{
  'fa': {
    'app_name': 'روان',
    'language': 'زبان',
    'cancel': 'انصراف', 'ok': 'باشه', 'save': 'ذخیره', 'submit': 'ثبت', 'notes': 'یادداشت',
    // auth
    'name': 'نام', 'email': 'ایمیل', 'password': 'رمز عبور', 'login': 'ورود', 'register': 'ثبت‌نام',
    'have_account': 'حساب دارم — ورود', 'no_account': 'حساب ندارم — ثبت‌نام (فقط مراجع)',
    'clinician_accounts_note': 'حساب درمانگران توسط ادمین پس از احراز هویت ساخته می‌شود.',
    // home
    'verify_clinicians': 'تأیید درمانگران', 'book': 'رزرو نوبت', 'join_session': 'ورود به جلسه', 'report': 'گزارش', 'confirm': 'تأیید',
    'status': 'وضعیت', 'mode_text': 'متنی', 'mode_audio': 'صوتی', 'mode_video': 'تصویری',
    'status_pending': 'در انتظار', 'status_confirmed': 'تأیید شده', 'status_cancelled': 'لغو شده', 'status_completed': 'انجام شده', 'status_no_show': 'عدم حضور',
    // clinicians
    'choose_clinician': 'انتخاب روان‌شناس / روان‌پزشک', 'specialty': 'تخصص', 'all_specialties': 'همه تخصص‌ها', 'session_type': 'نوع جلسه', 'all': 'همه',
    'verified': 'تأیید شده', 'minutes': '{n} دقیقه', 'currency_irr': 'ریال',
    'free_slots': 'زمان‌های آزاد (۱۴ روز آینده)', 'no_slots': 'زمان آزادی یافت نشد.',
    'booked_title': 'نوبت ثبت شد', 'booked_body': 'زمان: {time}\nپس از تأیید درمانگر، از صفحه اصلی وارد جلسه شوید.',
    // consent
    'before_start': 'پیش از شروع جلسه', 'version': 'نسخه {v}',
    'consent_footer': 'تحلیل رفتاری فقط اعداد توصیفی را استخراج می‌کند، فقط برای درمانگر و فقط به‌عنوان مشاهده نمایش داده می‌شود و هر لحظه قابل توقف است.',
    'continue_text_only': 'ادامه فقط با متن', 'confirm_join': 'تأیید و ورود به جلسه',
    // patient session
    'connecting': 'در حال اتصال…', 'in_session': 'در جلسه', 'session_title': 'جلسه — {status}', 'end_session': 'پایان جلسه',
    'waiting_clinician': 'در انتظار درمانگر…', 'camera_off': 'خاموش کردن دوربین', 'camera_on': 'روشن کردن دوربین',
    'analysis_on_label': 'تحلیل رفتاری فعال است (فقط اعداد، بدون ذخیره تصویر)', 'analysis_paused_label': 'تحلیل رفتاری متوقف است', 'analysis_disabled_label': 'تحلیل رفتاری غیرفعال است',
    'withdraw_consent': 'پس گرفتن رضایت', 'withdraw_title': 'پس گرفتن رضایت تحلیل',
    'withdraw_body': 'تحلیل بلافاصله متوقف می‌شود، جلسه ادامه می‌یابد و داده‌های مشتق‌شده این جلسه حذف خواهند شد.', 'withdraw_confirm': 'پس می‌گیرم',
    'transcription_withdrawn_notice': 'مراجع رضایت رونویسی را پس گرفت؛ ضبط متوقف شد.',
    'withdraw_transcription': 'توقف رونویسی',
    'withdraw_transcription_body': 'ضبط صدا همین حالا متوقف می‌شود، جلسه ادامه می‌یابد و متنِ ثبت‌شدهٔ این جلسه حذف خواهد شد.',
    'message_hint': 'پیام…',
    // doctor console
    'console': 'کنسول درمانگر', 'analysis_active': 'تحلیل رفتاری فعال (با رضایت بیمار)', 'analysis_inactive': 'تحلیل رفتاری غیرفعال',
    'topic_hint': 'موضوع فعلی (مثلاً خانواده)', 'end_and_report': 'پایان و گزارش', 'waiting_patient': 'در انتظار بیمار…',
    'clinician': 'درمانگر', 'patient': 'بیمار', 'note_hint': 'یادداشت بالینی (فقط شما می‌نویسید)', 'mark_moment': 'علامت‌گذاری این لحظه',
    'min_confidence': 'حداقل اطمینان',
    'no_events_yet': 'هنوز مشاهده‌ای ثبت نشده. تا ساخته شدن خط پایه (حدود ۵ دقیقه) فقط پرچم‌های کیفیت نمایش داده می‌شوند.',
    'console_footer': 'همه موارد «مشاهده» هستند، نه تشخیص. مقایسه فقط با خط پایه همین بیمار در همین جلسه انجام می‌شود.',
    'safety_banner': 'عبارت صریح مرتبط با ایمنی در متن ({t}): «{text}» — فقط ارجاع به متن، بدون امتیاز خطر.', 'seen': 'دیدم',
    // event card
    'tier_quality': 'کیفیت / فنی', 'tier_observation': 'مشاهده', 'tier_change': 'تغییر نسبت به خط پایه', 'tier_cluster': 'خوشه چندوجهی',
    'tier_content': 'محتوای کلامی', 'tier_medical': 'احتمال توضیح جسمی/پزشکی', 'tier_safety': 'محتوای مرتبط با ایمنی — بررسی فوری',
    'confidence': 'اطمینان {p}٪', 'baseline': 'خط پایه', 'current': 'فعلی', 'detection_quality': 'کیفیت تشخیص',
    'after_question': 'حدود {s} ثانیه پس از سؤال: «{q}»', 'cluster_members': 'اعضای خوشه', 'patient_said': 'گفته بیمار',
    'possible_contexts': 'زمینه‌های محتمل (نه نتیجه‌گیری):', 'why_shown': 'چرا این مشاهده نمایش داده می‌شود؟', 'note_prefix': 'توجه',
    'relevant': 'مرتبط است', 'dismiss': 'رد', 'transcript_here': 'متن این لحظه', 'clinician_note': 'یادداشت درمانگر',
    // report
    'report_title': 'گزارش جلسه — پیش‌نویس برای بازبینی درمانگر', 'duration': 'مدت', 'clinician_speech': 'گفتار درمانگر', 'patient_speech': 'گفتار بیمار',
    'silence': 'سکوت', 'notable_changes': 'تغییرات قابل توجه', 'clusters': 'خوشه‌ها', 'baseline_quality': 'کیفیت خط پایه', 'coverage': 'پوشش', 'seconds': 'ثانیه',
    'ai_summary': 'پیش‌نویس خلاصه (هوش مصنوعی)', 'notable_change': 'تغییر قابل توجه', 'cluster': 'خوشه چندوجهی', 'members': 'اعضا',
    'your_text': 'متن شما', 'accepted': 'تأیید', 'edited': 'ویرایش', 'rejected': 'رد',
    'final_summary': 'خلاصه نهایی درمانگر (فقط این متن به پرونده می‌رود)', 'save_version': 'ذخیره نسخه', 'finalize': 'نهایی کردن گزارش',
    'finalized_msg': 'گزارش نهایی شد', 'saved_msg': 'نسخه جدید ذخیره شد', 'vs_baseline': '{t} — {obs} (فعلی {cur} در برابر خط پایه {base})',
    // admin
    'admin_title': 'احراز هویت درمانگران', 'pending': 'در انتظار', 'approved': 'تأیید شده', 'rejected_s': 'رد شده', 'suspended': 'معلق',
    'decision_title': 'تصمیم: {d} — {name}', 'license_valid': 'مجوز معتبر و فعال', 'identity_matched': 'هویت با مدارک تطابق دارد', 'degree_verified': 'مدرک تحصیلی تأیید شد',
    'approve': 'تأیید', 'reject': 'رد', 'suspend': 'تعلیق', 'license_no': 'شماره مجوز', 'documents': 'مدارک',
  },
  'en': {
    'app_name': 'Ravan',
    'language': 'Language',
    'cancel': 'Cancel', 'ok': 'OK', 'save': 'Save', 'submit': 'Submit', 'notes': 'Notes',
    'name': 'Name', 'email': 'Email', 'password': 'Password', 'login': 'Sign in', 'register': 'Sign up',
    'have_account': 'I have an account — sign in', 'no_account': 'No account — sign up (patients only)',
    'clinician_accounts_note': 'Clinician accounts are created by an admin after credential verification.',
    'verify_clinicians': 'Verify clinicians', 'book': 'Book a session', 'join_session': 'Join session', 'report': 'Report', 'confirm': 'Confirm',
    'status': 'Status', 'mode_text': 'Text', 'mode_audio': 'Audio', 'mode_video': 'Video',
    'status_pending': 'Pending', 'status_confirmed': 'Confirmed', 'status_cancelled': 'Cancelled', 'status_completed': 'Completed', 'status_no_show': 'No-show',
    'choose_clinician': 'Choose a psychologist / psychiatrist', 'specialty': 'Specialty', 'all_specialties': 'All specialties', 'session_type': 'Session type', 'all': 'All',
    'verified': 'Verified', 'minutes': '{n} min', 'currency_irr': 'IRR',
    'free_slots': 'Available times (next 14 days)', 'no_slots': 'No available times found.',
    'booked_title': 'Appointment booked', 'booked_body': 'Time: {time}\nOnce the clinician confirms, join the session from the home screen.',
    'before_start': 'Before the session starts', 'version': 'Version {v}',
    'consent_footer': 'Behavioural analysis extracts descriptive numbers only, is shown only to your clinician and only as observations, and can be paused at any moment.',
    'continue_text_only': 'Continue with text only', 'confirm_join': 'Confirm and join',
    'connecting': 'Connecting…', 'in_session': 'In session', 'session_title': 'Session — {status}', 'end_session': 'End session',
    'waiting_clinician': 'Waiting for the clinician…', 'camera_off': 'Turn camera off', 'camera_on': 'Turn camera on',
    'analysis_on_label': 'Behavioural analysis is on (numbers only, no video stored)', 'analysis_paused_label': 'Behavioural analysis is paused', 'analysis_disabled_label': 'Behavioural analysis is off',
    'withdraw_consent': 'Withdraw consent', 'withdraw_title': 'Withdraw analysis consent',
    'withdraw_body': 'Analysis stops immediately, the call continues, and the derived data for this session will be deleted.', 'withdraw_confirm': 'Withdraw',
    'transcription_withdrawn_notice': 'The patient withdrew transcription consent; recording has stopped.',
    'withdraw_transcription': 'Stop transcription',
    'withdraw_transcription_body': 'Recording stops right now, the call continues, and the transcript of this session will be deleted.',
    'message_hint': 'Message…',
    'console': 'Clinician console', 'analysis_active': 'Behavioural analysis active (with patient consent)', 'analysis_inactive': 'Behavioural analysis off',
    'topic_hint': 'Current topic (e.g. family)', 'end_and_report': 'End and report', 'waiting_patient': 'Waiting for the patient…',
    'clinician': 'Clinician', 'patient': 'Patient', 'note_hint': 'Clinical note (written only by you)', 'mark_moment': 'Mark this moment',
    'min_confidence': 'Min. confidence',
    'no_events_yet': 'No observations yet. Until the baseline is built (about 5 minutes) only quality flags are shown.',
    'console_footer': 'Everything here is an observation, not a diagnosis. Comparison is only with this patient\'s baseline in this session.',
    'safety_banner': 'Explicit safety-relevant statement in the transcript ({t}): "{text}" — a pointer to the text only, no risk score.', 'seen': 'Seen',
    'tier_quality': 'Quality / technical', 'tier_observation': 'Observation', 'tier_change': 'Change from baseline', 'tier_cluster': 'Multimodal cluster',
    'tier_content': 'Verbal content', 'tier_medical': 'Consider physical / medical explanation', 'tier_safety': 'Safety-relevant content — review now',
    'confidence': 'Confidence {p}%', 'baseline': 'Baseline', 'current': 'Current', 'detection_quality': 'Detection quality',
    'after_question': 'About {s} s after the question: "{q}"', 'cluster_members': 'Cluster members', 'patient_said': 'Patient said',
    'possible_contexts': 'Possible contexts (not conclusions):', 'why_shown': 'Why is this observation shown?', 'note_prefix': 'Note',
    'relevant': 'Mark relevant', 'dismiss': 'Dismiss', 'transcript_here': 'Transcript here', 'clinician_note': 'Clinician note',
    'report_title': 'Session report — draft for clinician review', 'duration': 'Duration', 'clinician_speech': 'Clinician speaking', 'patient_speech': 'Patient speaking',
    'silence': 'Silence', 'notable_changes': 'Notable changes', 'clusters': 'Clusters', 'baseline_quality': 'Baseline quality', 'coverage': 'Coverage', 'seconds': 's',
    'ai_summary': 'Draft summary (AI)', 'notable_change': 'Notable change', 'cluster': 'Multimodal cluster', 'members': 'members',
    'your_text': 'Your text', 'accepted': 'Accept', 'edited': 'Edit', 'rejected': 'Reject',
    'final_summary': 'Clinician\'s final summary (only this text goes to the record)', 'save_version': 'Save version', 'finalize': 'Finalize report',
    'finalized_msg': 'Report finalized', 'saved_msg': 'New version saved', 'vs_baseline': '{t} — {obs} (current {cur} vs baseline {base})',
    'admin_title': 'Clinician verification', 'pending': 'Pending', 'approved': 'Approved', 'rejected_s': 'Rejected', 'suspended': 'Suspended',
    'decision_title': 'Decision: {d} — {name}', 'license_valid': 'License valid and active', 'identity_matched': 'Identity matches documents', 'degree_verified': 'Degree verified',
    'approve': 'Approve', 'reject': 'Reject', 'suspend': 'Suspend', 'license_no': 'License no.', 'documents': 'Documents',
  },
  'tr': {
    'app_name': 'Ravan',
    'language': 'Dil',
    'cancel': 'İptal', 'ok': 'Tamam', 'save': 'Kaydet', 'submit': 'Gönder', 'notes': 'Notlar',
    'name': 'Ad', 'email': 'E-posta', 'password': 'Parola', 'login': 'Giriş yap', 'register': 'Kayıt ol',
    'have_account': 'Hesabım var — giriş yap', 'no_account': 'Hesabım yok — kayıt ol (yalnızca danışanlar)',
    'clinician_accounts_note': 'Klinisyen hesapları kimlik doğrulamasından sonra yönetici tarafından oluşturulur.',
    'verify_clinicians': 'Klinisyen doğrulama', 'book': 'Randevu al', 'join_session': 'Seansa katıl', 'report': 'Rapor', 'confirm': 'Onayla',
    'status': 'Durum', 'mode_text': 'Yazılı', 'mode_audio': 'Sesli', 'mode_video': 'Görüntülü',
    'status_pending': 'Beklemede', 'status_confirmed': 'Onaylandı', 'status_cancelled': 'İptal edildi', 'status_completed': 'Tamamlandı', 'status_no_show': 'Gelmedi',
    'choose_clinician': 'Psikolog / psikiyatrist seçin', 'specialty': 'Uzmanlık', 'all_specialties': 'Tüm uzmanlıklar', 'session_type': 'Seans türü', 'all': 'Tümü',
    'verified': 'Doğrulanmış', 'minutes': '{n} dk', 'currency_irr': 'IRR',
    'free_slots': 'Uygun saatler (sonraki 14 gün)', 'no_slots': 'Uygun saat bulunamadı.',
    'booked_title': 'Randevu oluşturuldu', 'booked_body': 'Zaman: {time}\nKlinisyen onayladıktan sonra ana ekrandan seansa katılın.',
    'before_start': 'Seans başlamadan önce', 'version': 'Sürüm {v}',
    'consent_footer': 'Davranış analizi yalnızca betimleyici sayılar çıkarır, yalnızca klinisyeninize ve yalnızca gözlem olarak gösterilir ve her an duraklatılabilir.',
    'continue_text_only': 'Yalnızca yazılı devam et', 'confirm_join': 'Onayla ve katıl',
    'connecting': 'Bağlanıyor…', 'in_session': 'Seansta', 'session_title': 'Seans — {status}', 'end_session': 'Seansı bitir',
    'waiting_clinician': 'Klinisyen bekleniyor…', 'camera_off': 'Kamerayı kapat', 'camera_on': 'Kamerayı aç',
    'analysis_on_label': 'Davranış analizi açık (yalnızca sayılar, görüntü kaydı yok)', 'analysis_paused_label': 'Davranış analizi duraklatıldı', 'analysis_disabled_label': 'Davranış analizi kapalı',
    'withdraw_consent': 'Onayı geri çek', 'withdraw_title': 'Analiz onayını geri çek',
    'withdraw_body': 'Analiz hemen durur, görüşme devam eder ve bu seansın türetilmiş verileri silinir.', 'withdraw_confirm': 'Geri çekiyorum',
    'transcription_withdrawn_notice': 'Danışan yazıya dökme onayını geri çekti; kayıt durduruldu.',
    'withdraw_transcription': 'Yazıya dökmeyi durdur',
    'withdraw_transcription_body': 'Kayıt hemen durur, görüşme devam eder ve bu seansın metni silinir.',
    'message_hint': 'Mesaj…',
    'console': 'Klinisyen konsolu', 'analysis_active': 'Davranış analizi etkin (hasta onayıyla)', 'analysis_inactive': 'Davranış analizi kapalı',
    'topic_hint': 'Güncel konu (örn. aile)', 'end_and_report': 'Bitir ve raporla', 'waiting_patient': 'Hasta bekleniyor…',
    'clinician': 'Klinisyen', 'patient': 'Hasta', 'note_hint': 'Klinik not (yalnızca siz yazarsınız)', 'mark_moment': 'Bu anı işaretle',
    'min_confidence': 'En az güven',
    'no_events_yet': 'Henüz gözlem yok. Taban çizgisi oluşana kadar (yaklaşık 5 dakika) yalnızca kalite bayrakları gösterilir.',
    'console_footer': 'Buradaki her şey bir gözlemdir, tanı değil. Karşılaştırma yalnızca bu hastanın bu seanstaki taban çizgisiyle yapılır.',
    'safety_banner': 'Transkriptte güvenlikle ilgili açık ifade ({t}): "{text}" — yalnızca metne yönlendirme, risk puanı yok.', 'seen': 'Gördüm',
    'tier_quality': 'Kalite / teknik', 'tier_observation': 'Gözlem', 'tier_change': 'Taban çizgisine göre değişim', 'tier_cluster': 'Çok kanallı küme',
    'tier_content': 'Sözel içerik', 'tier_medical': 'Fiziksel / tıbbi açıklamayı düşünün', 'tier_safety': 'Güvenlikle ilgili içerik — hemen inceleyin',
    'confidence': 'Güven %{p}', 'baseline': 'Taban çizgisi', 'current': 'Güncel', 'detection_quality': 'Algılama kalitesi',
    'after_question': 'Sorudan yaklaşık {s} sn sonra: "{q}"', 'cluster_members': 'Küme üyeleri', 'patient_said': 'Hasta dedi ki',
    'possible_contexts': 'Olası bağlamlar (sonuç değil):', 'why_shown': 'Bu gözlem neden gösteriliyor?', 'note_prefix': 'Not',
    'relevant': 'İlgili', 'dismiss': 'Reddet', 'transcript_here': 'Bu anın metni', 'clinician_note': 'Klinisyen notu',
    'report_title': 'Seans raporu — klinisyen incelemesi için taslak', 'duration': 'Süre', 'clinician_speech': 'Klinisyen konuşması', 'patient_speech': 'Hasta konuşması',
    'silence': 'Sessizlik', 'notable_changes': 'Dikkat çeken değişimler', 'clusters': 'Kümeler', 'baseline_quality': 'Taban çizgisi kalitesi', 'coverage': 'Kapsam', 'seconds': 'sn',
    'ai_summary': 'Taslak özet (yapay zekâ)', 'notable_change': 'Dikkat çeken değişim', 'cluster': 'Çok kanallı küme', 'members': 'üye',
    'your_text': 'Sizin metniniz', 'accepted': 'Onayla', 'edited': 'Düzenle', 'rejected': 'Reddet',
    'final_summary': 'Klinisyenin nihai özeti (kayda yalnızca bu metin girer)', 'save_version': 'Sürümü kaydet', 'finalize': 'Raporu sonlandır',
    'finalized_msg': 'Rapor sonlandırıldı', 'saved_msg': 'Yeni sürüm kaydedildi', 'vs_baseline': '{t} — {obs} (güncel {cur}, taban çizgisi {base})',
    'admin_title': 'Klinisyen kimlik doğrulama', 'pending': 'Beklemede', 'approved': 'Onaylandı', 'rejected_s': 'Reddedildi', 'suspended': 'Askıda',
    'decision_title': 'Karar: {d} — {name}', 'license_valid': 'Lisans geçerli ve etkin', 'identity_matched': 'Kimlik belgelerle eşleşiyor', 'degree_verified': 'Diploma doğrulandı',
    'approve': 'Onayla', 'reject': 'Reddet', 'suspend': 'Askıya al', 'license_no': 'Lisans no', 'documents': 'Belgeler',
  },
};

/// Small dropdown for the app bar.
class LanguageSwitcher extends StatelessWidget {
  const LanguageSwitcher({super.key});

  @override
  Widget build(BuildContext context) {
    final store = LocaleScope.of(context);
    return _LangDropdown(value: store.code, onChanged: store.set);
  }
}

class _LangDropdown extends StatelessWidget {
  const _LangDropdown({required this.value, required this.onChanged});
  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          value: value,
          icon: const Icon(IconData(0xe8e2, fontFamily: 'MaterialIcons'), size: 18), // translate icon
          items: [for (final c in supportedLangs) DropdownMenuItem(value: c, child: Text(langNames[c]!))],
          onChanged: (v) => v == null ? null : onChanged(v),
        ),
      ),
    );
  }
}
