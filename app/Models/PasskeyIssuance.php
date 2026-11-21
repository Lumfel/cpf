<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campus_id', 'target_user_id', 'issued_by_user_id', 'enrollment_code_hash', 'encrypted_enrollment_code', 'require_2fa', 'status', 'is_locked', 'expires_at', 'claimed_at', 'emailed_at'])]
class PasskeyIssuance extends Model
{
    protected function casts(): array
    {
        return [
            'encrypted_enrollment_code' => 'encrypted',
            'require_2fa' => 'boolean',
            'is_locked' => 'boolean',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    public function targetUser(): BelongsTo { return $this->belongsTo(User::class, 'target_user_id'); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by_user_id'); }
}
