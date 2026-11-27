<?php

namespace App\Events;

use App\Models\CounselorGcMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CounselorGcMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $campusId,
        public CounselorGcMessage $message,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("campus.{$this->campusId}.counselors-gc"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'counselor.gc.message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'campus_id' => $this->campusId,
            'sender_user_id' => $this->message->sender_user_id,
            'sender_name' => $this->message->sender?->name ?? 'Guidance Counselor',
            'sender' => $this->message->sender ? [
                'id' => $this->message->sender->id,
                'name' => $this->message->sender->name,
            ] : null,
            'sender_role' => $this->message->sender_role,
            'message_body' => $this->message->message_body,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
