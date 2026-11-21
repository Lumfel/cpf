<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_event_id',
    'user_id',
    'time_capsule_id',
    'submitted_at',
])]
class CampusEventReflection extends Model
{
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
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

    public function timeCapsule(): BelongsTo
    {
        return $this->belongsTo(TimeCapsule::class);
    }
}
