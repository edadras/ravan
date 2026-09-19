<?php

namespace App\Events;

use App\Models\BehaviorEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the clinician's live timeline only. The patient channel never
 * receives behaviour events (principle: patient_facing_labels = false).
 */
class BehaviorEventCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public BehaviorEvent $event) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('session.'.$this->event->session->uuid.'.clinician')];
    }

    public function broadcastAs(): string
    {
        return 'behavior.event';
    }

    public function broadcastWith(): array
    {
        return $this->event->toArray();
    }
}
