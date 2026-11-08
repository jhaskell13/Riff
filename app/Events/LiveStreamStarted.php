<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveStreamStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public int $streamId)
    {
        \Log::info('STREAM STARTED!');
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        \Log::info('broadcastOn');

        return [
            new Channel("livestream.{$this->streamId}"),
        ];
    }

    public function broadcastWith(): array
    {
        \Log::info('broadcastWith');

        return [
            'stream_id' => $this->streamId,
            'status' => 'live',
            'started_at' => now()->toISOString(),
        ];
    }
}
