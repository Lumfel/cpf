<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_event_id',
    'user_id',
    'logged_in_at',
    'logged_in_by_user_id',
    'logged_out_at',
    'logged_out_by_user_id',
])]
class CampusEventAttendance extends Model
{
    protected function casts(): array
    {
        return [
            'logged_in_at' => 'datetime',
            'logged_out_at' => 'datetime',
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

    public function loggedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_in_by_user_id');
    }

    public function loggedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_out_by_user_id');
    }
}
