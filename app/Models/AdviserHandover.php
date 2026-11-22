<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'from_user_id',
    'to_user_id',
    'to_membership_id',
    'status',
    'outgoing_role',
    'confirmation_code',
    'handover_notes',
    'initiated_at',
    'expires_at',
    'responded_at',
])]
class AdviserHandover extends Model
{
    protected function casts(): array
    {
        return [
            'initiated_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function toMembership(): BelongsTo
    {
        return $this->belongsTo(CampusMembership::class, 'to_membership_id');
    }

    public function isPendingAndActive(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }
}
