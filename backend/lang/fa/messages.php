<?php

return [
    'clinician_unavailable' => 'این درمانگر برای نوبت جدید در دسترس نیست.',
    'mode_not_offered' => 'این نوع جلسه توسط درمانگر ارائه نمی‌شود.',
    'session_closed' => 'جلسه بسته شده است.',
    'session_not_live' => 'جلسه هنوز شروع نشده است.',
    'analysis_consent_missing' => 'تحلیل رفتاری نیازمند رضایت تماس و رضایت تحلیل از سوی مراجع است.',
    'transcription_consent_missing' => 'رونویسی نیازمند رضایت رونویسی از سوی مراجع است.',
    'analysis_unavailable' => 'سرویس تحلیل در دسترس نیست.',
    'forbidden_role' => 'نقش شما مجاز به انجام این عمل نیست.',
    'invalid_signature' => 'امضای وب‌هوک نامعتبر است.',
    'diagnostic_claim_rejected' => 'ادعای تشخیصی پذیرفته نمی‌شود.',
    'no_relationship' => 'پیام‌رسانی فقط بین مراجع و درمانگری که نوبت دارند ممکن است.',
    'already_paid' => 'این نوبت قبلاً پرداخت شده است.',

    // Shown with the transcription consent only when the server is configured
    // to use an engine outside this deployment (config/ravan.php -> asr).
    'transcription_remote_processor' => 'برای تبدیل گفتار به متن، تکه‌های صدای این جلسه به سرویس :processor فرستاده می‌شود، که خارج از این سامانه است. اگر با این موضوع موافق نیستید، این گزینه را روشن نکنید؛ جلسه بدون رونویسی به‌طور کامل برگزار می‌شود.',
    'transcription_remote_bullet' => 'صدا برای رونویسی به :processor فرستاده می‌شود',
];
