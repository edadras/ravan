<?php

use App\Models\TherapySession;
use Illuminate\Support\Facades\Broadcast;

// Clinician-only channel: behaviour events, transcript, chat, status.
Broadcast::channel('session.{uuid}.clinician', function ($user, string $uuid) {
    $session = TherapySession::where('uuid', $uuid)->first();

    return $session && $user->id === $session->clinician_id;
});

// Patient channel: transcript, chat, status. Never behaviour events.
Broadcast::channel('session.{uuid}.patient', function ($user, string $uuid) {
    $session = TherapySession::where('uuid', $uuid)->first();

    return $session && $user->id === $session->patient_id;
});
