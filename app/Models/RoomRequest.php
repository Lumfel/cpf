<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campus_id', 'room_id', 'counselor_id', 'requested_by_user_id', 'purpose', 'request_date', 'start_time', 'end_time', 'status', 'reviewed_by_user_id', 'review_notes'])]
class RoomRequest extends Model
{
    protected function casts(): array
    {
        return ['request_date' => 'date:Y-m-d'];
    }

    public function room(): BelongsTo { return $this->belongsTo(CampusRoom::class, 'room_id'); }
    public function counselor(): BelongsTo { return $this->belongsTo(CampusCounselor::class, 'counselor_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by_user_id'); }
}
