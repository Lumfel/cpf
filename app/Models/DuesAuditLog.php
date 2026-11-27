<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'actor_user_id',
    'target_user_id',
    'campus_membership_id',
    'payment_submission_id',
    'old_status',
    'new_status',
    'reason',
    'created_at',
])]
class DuesAuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'old_status' => 'boolean',
            'new_status' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(CampusMembership::class, 'campus_membership_id');
    }

    public function paymentSubmission(): BelongsTo
    {
        return $this->belongsTo(PaymentSubmission::class, 'payment_submission_id');
    }
}
