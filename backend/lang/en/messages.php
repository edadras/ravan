<?php

return [
    'clinician_unavailable' => 'This clinician is not available for new appointments.',
    'mode_not_offered' => 'This session mode is not offered by the clinician.',
    'session_closed' => 'The session is closed.',
    'session_not_live' => 'The session is not live yet.',
    'analysis_consent_missing' => 'Behavioural analysis requires the patient\'s call and analysis consents.',
    'transcription_consent_missing' => 'Transcription requires the patient\'s transcription consent.',
    'analysis_unavailable' => 'The analysis service is unavailable.',
    'forbidden_role' => 'Your role is not allowed to perform this action.',
    'invalid_signature' => 'Invalid webhook signature.',
    'diagnostic_claim_rejected' => 'Diagnostic claims are not accepted.',
    'no_relationship' => 'Messaging is available only between a patient and a clinician with an appointment.',
    'already_paid' => 'This appointment is already paid.',

    // Shown with the transcription consent only when the server is configured
    // to use an engine outside this deployment (config/ravan.php -> asr).
    'transcription_remote_processor' => 'To turn speech into text, short chunks of this session\'s audio are sent to :processor, a service outside this platform. If you are not comfortable with that, leave this option off; the session works fully without transcription.',
    'transcription_remote_bullet' => 'Audio is sent to :processor for transcription',
];
