<?php

namespace App\Events;

use App\Models\TranscriptSegment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TranscriptSegmentCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TranscriptSegment $segment) {}

    public function broadcastOn(): array
    {
        $uuid = $this->segment->session->uuid;

        return [new PrivateChannel("session.{$uuid}.clinician"), new PrivateChannel("session.{$uuid}.patient")];
    }

    public function broadcastAs(): string
    {
        return 'transcript.segment';
    }

    public function broadcastWith(): array
    {
        return $this->segment->only(['uuid', 'speaker', 't_start_ms', 't_end_ms', 'text', 'confidence', 'is_question']);
    }
}
