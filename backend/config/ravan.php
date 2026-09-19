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
            'transcription' => env('RAVAN_CONSENT_TRANSCRIPTION_VERSION', '1.0'),
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
    | Catalog
    |--------------------------------------------------------------------------
    */
    'catalog_path' => env('RAVAN_CATALOG_PATH', base_path('../catalog/signal_catalog.json')),
];
