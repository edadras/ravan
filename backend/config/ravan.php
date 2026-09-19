<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Analysis service (Python, analysis-service/)
    |--------------------------------------------------------------------------
    */
    'analysis' => [
        'base_url' => env('RAVAN_ANALYSIS_URL', 'http://analysis:8100'),
        'token' => env('RAVAN_ANALYSIS_TOKEN', ''),
        // The WebSocket address the browser uses. Inside the deployment the
        // service is reached at base_url; the browser goes through the proxy.
        'public_ws_url' => env('RAVAN_ANALYSIS_PUBLIC_WS', ''),
        // Lifetime of the per-session ingest credential handed to the browser.
        'ingest_token_ttl_s' => (int) env('RAVAN_ANALYSIS_INGEST_TTL_S', 4 * 3600),
        // shared secret used by the analysis service to sign event webhooks (HMAC-SHA256)
        'webhook_secret' => env('RAVAN_WEBHOOK_SECRET', ''),
        // public URL of this backend that the analysis service can reach
        'webhook_url' => env('RAVAN_WEBHOOK_URL', env('APP_URL').'/api/webhooks/analysis/events'),
        'baseline_window_s' => (int) env('RAVAN_BASELINE_WINDOW_S', 300),
        'timeout_s' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time media (WebRTC SFU)
    |--------------------------------------------------------------------------
    | provider: livekit | janus | mediasoup (token generation implemented for livekit)
    */
    'webrtc' => [
        'provider' => env('RAVAN_WEBRTC_PROVIDER', 'livekit'),
        'url' => env('RAVAN_WEBRTC_URL', 'wss://livekit.example'),
        'api_key' => env('RAVAN_WEBRTC_API_KEY', ''),
        'api_secret' => env('RAVAN_WEBRTC_API_SECRET', ''),
        'token_ttl_s' => 60 * 60 * 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Consent
    |--------------------------------------------------------------------------
    */
    'consent' => [
        // bump when the consent text changes; older consents are not valid for new sessions
        'current_versions' => [
            'video_call' => env('RAVAN_CONSENT_VIDEO_VERSION', '1.0'),
            'behavior_analysis' => env('RAVAN_CONSENT_ANALYSIS_VERSION', '1.0'),
            // The transcription consent version carries the processor, because
            // who hears the patient's voice is part of what is being consented
            // to. Switching the server from local recognition to a third-party
            // API therefore invalidates every consent granted under the old
            // arrangement, and each patient is asked again.
            'transcription' => env('RAVAN_CONSENT_TRANSCRIPTION_VERSION', '1.0')
                .(in_array(env('RAVAN_ASR_BACKEND', 'faster_whisper'), ['openai'], true) ? '-remote' : ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (days). null = keep until explicit deletion.
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'behavior_events_days' => env('RAVAN_RETENTION_EVENTS_DAYS', 365),
        'transcripts_days' => env('RAVAN_RETENTION_TRANSCRIPTS_DAYS', 365),
        'raw_media_days' => 0, // raw video/audio is never stored by default
        'withdrawn_consent_purge_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Speech recognition service (asr-service/)
    |--------------------------------------------------------------------------
    */
    'asr' => [
        'base_url' => env('RAVAN_ASR_URL', 'http://asr:8200'),
        'token' => env('RAVAN_ASR_TOKEN', ''),
        // Which engine transcribes the audio. `faster_whisper` and `fake` run
        // inside this deployment; `openai` uploads the patient's speech to a
        // third party, which the consent text has to say out loud.
        'backend' => env('RAVAN_ASR_BACKEND', 'faster_whisper'),
        'remote_backends' => ['openai' => 'OpenAI'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    | gateway: sandbox | zarinpal
    */
    'payments' => [
        'gateway' => env('RAVAN_PAYMENT_GATEWAY', 'sandbox'),
        'platform_fee_ratio' => (float) env('RAVAN_PLATFORM_FEE_RATIO', 0.15),
    ],

    'terms_version' => env('RAVAN_TERMS_VERSION', '1.0'),

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    */
    'catalog_path' => env('RAVAN_CATALOG_PATH', base_path('../catalog/signal_catalog.json')),
];
