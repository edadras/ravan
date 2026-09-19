<?php

namespace App\Events;

use App\Enums\ConsentType;
use App\Models\TherapySession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TherapySession $session, public string $reason = '') {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("session.{$this->session->uuid}.clinician"), new PrivateChannel("session.{$this->session->uuid}.patient")];
    }

    public function broadcastAs(): string
    {
        return 'session.status';
    }

    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->session->uuid,
            'status' => $this->session->status->value,
            'analysis_enabled' => $this->session->analysis_enabled,
            // Both participants record their own microphone, so the clinician's
            // recorder has to learn about a withdrawal made in the patient's
            // browser. Without this it kept uploading chunks the server then
            // refused, and the clinician saw no sign that anything had changed.
            'transcription_allowed' => $this->session->hasActiveConsent(ConsentType::Transcription),
            'reason' => $this->reason,
        ];
    }
}
