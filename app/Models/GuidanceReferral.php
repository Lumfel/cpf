<?php

namespace App\Models;

use App\Enums\ReferralPriority;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['campus_id', 'submitted_by_user_id', 'referral_type', 'student_identifier', 'student_course_section', 'suggested_priority', 'counselor_confirmed_priority', 'triage_response_target', 'encrypted_concern_summary', 'status', 'assigned_counselor_id'])]
class GuidanceReferral extends Model
{
    protected function casts(): array
    {
        return [
            'suggested_priority' => ReferralPriority::class,
            'counselor_confirmed_priority' => ReferralPriority::class,
            'encrypted_concern_summary' => 'encrypted',
        ];
    }

    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by_user_id'); }
    public function auditLogs(): HasMany { return $this->hasMany(ReferralAuditLog::class, 'referral_id'); }
}
