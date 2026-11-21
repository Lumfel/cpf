<?php

namespace App\Events;

use App\Models\OfficerGcChannel;
use App\Models\OfficerGcMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OfficerGcMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public OfficerGcChannel $channel,
        public OfficerGcMessage $message,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("campus.{$this->channel->campus_id}.officers-gc.{$this->channel->academic_year}"),
        ];
    }
}
