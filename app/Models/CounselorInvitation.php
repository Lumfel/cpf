<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'invited_by_user_id',
    'email',
    'counselor_name',
    'token',
    'otp_hash',
    'failed_attempts',
    'expires_at',
    'accepted_at',
    'accepted_user_id',
])]
#[Hidden(['otp_hash', 'token'])]
class CounselorInvitation extends Model
{
    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function isPendingAndActive(): bool
    {
        return $this->accepted_at === null
            && (int) $this->failed_attempts < 5
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }
}
