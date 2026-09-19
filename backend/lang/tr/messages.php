<?php

return [
    'clinician_unavailable' => 'Bu klinisyen yeni randevular için uygun değil.',
    'mode_not_offered' => 'Bu seans türü klinisyen tarafından sunulmuyor.',
    'session_closed' => 'Seans kapatıldı.',
    'session_not_live' => 'Seans henüz başlamadı.',
    'analysis_consent_missing' => 'Davranış analizi, hastanın görüşme ve analiz onaylarını gerektirir.',
    'transcription_consent_missing' => 'Yazıya dökme, hastanın yazıya dökme onayını gerektirir.',
    'analysis_unavailable' => 'Analiz hizmeti kullanılamıyor.',
    'forbidden_role' => 'Rolünüz bu işlemi yapmaya yetkili değil.',
    'invalid_signature' => 'Geçersiz webhook imzası.',
    'diagnostic_claim_rejected' => 'Tanısal iddialar kabul edilmez.',
    'no_relationship' => 'Mesajlaşma yalnızca randevusu olan danışan ve klinisyen arasında mümkündür.',
    'already_paid' => 'Bu randevu zaten ödendi.',

    // Shown with the transcription consent only when the server is configured
    // to use an engine outside this deployment (config/ravan.php -> asr).
    'transcription_remote_processor' => 'Konuşmayı yazıya dökmek için bu seansın kısa ses parçaları, bu platformun dışındaki :processor hizmetine gönderilir. Bunu istemiyorsanız bu seçeneği açmayın; seans yazıya dökme olmadan da eksiksiz çalışır.',
    'transcription_remote_bullet' => 'Ses, yazıya dökme için :processor hizmetine gönderilir',
];
