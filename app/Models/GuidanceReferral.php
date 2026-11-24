<?php

namespace App\Models;

use App\Enums\ReferralPriority;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'campus_id',
    'submitted_by_user_id',
    'referral_type',
    'student_identifier',
    'student_course_section',
    'suggested_priority',
    'counselor_confirmed_priority',
    'triage_response_target',
    'encrypted_concern_summary',
    'status',
    'feedback_status',
    'safe_scheduling_note',
    'confidential_clinical_notes',
    'assigned_counselor_id',
    'counselor_id',
    'room_request_id',
    'preferred_date',
    'preferred_start_time',
    'preferred_end_time',
])]
#[Hidden(['encrypted_concern_summary', 'confidential_clinical_notes'])]
class GuidanceReferral extends Model
{
    protected $attributes = [
        'feedback_status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'suggested_priority' => ReferralPriority::class,
            'counselor_confirmed_priority' => ReferralPriority::class,
            'encrypted_concern_summary' => 'encrypted',
            'confidential_clinical_notes' => 'encrypted',
            'preferred_date' => 'date',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(CampusCounselor::class, 'counselor_id');
    }

    public function roomRequest(): BelongsTo
    {
        return $this->belongsTo(RoomRequest::class, 'room_request_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ReferralAuditLog::class, 'referral_id');
    }
}
