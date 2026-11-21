<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'campus_event_id',
    'user_id',
    'type',
    'title',
    'message',
    'read_at',
])]
class CampusEventNotification extends Model
{
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CampusEvent::class, 'campus_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
