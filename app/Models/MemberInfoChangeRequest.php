<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_membership_id',
    'campus_id',
    'user_id',
    'requested_changes',
    'reason',
    'status',
    'reviewed_by',
    'reviewed_at',
    'adviser_notes',
])]
class MemberInfoChangeRequest extends Model
{
    protected function casts(): array
    {
        return [
            'requested_changes' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(CampusMembership::class, 'campus_membership_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
