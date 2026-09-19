<?php

namespace App\Events;

use App\Models\SessionMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SessionMessage $message, public string $sessionUuid) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("session.{$this->sessionUuid}.clinician"), new PrivateChannel("session.{$this->sessionUuid}.patient")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    public function broadcastWith(): array
    {
        return $this->message->only(['id', 'sender_id', 'body', 't_ms', 'created_at']);
    }
}
