<?php

namespace App\Events;

use App\Models\ConversationMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ConversationMessage $message) {}

    public function broadcastOn(): array
    {
        $c = $this->message->conversation;

        return [new PrivateChannel("user.{$c->patient_id}"), new PrivateChannel("user.{$c->clinician_id}")];
    }

    public function broadcastAs(): string
    {
        return 'conversation.message';
    }

    public function broadcastWith(): array
    {
        return $this->message->only(['id', 'conversation_id', 'sender_id', 'body', 'created_at']);
    }
}
