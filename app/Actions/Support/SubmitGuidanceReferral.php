<?php

namespace App\Actions\Support;

use App\Enums\ReferralPriority;
use App\Models\GuidanceReferral;
use App\Models\ReferralAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubmitGuidanceReferral
{
    public function handle(User $submitter, int $campusId, array $validated, ?string $ipAddress = null): GuidanceReferral
    {
        $priority = ReferralPriority::from((string) ($validated['suggested_priority'] ?? 'routine'));

        return DB::transaction(function () use ($submitter, $campusId, $validated, $priority, $ipAddress): GuidanceReferral {
            $referral = GuidanceReferral::create([
                'campus_id' => $campusId,
                'submitted_by_user_id' => $submitter->id,
                'referral_type' => $validated['referral_type'] ?? 'peer_referral',
                'student_identifier' => trim((string) $validated['student_identifier']),
                'student_course_section' => isset($validated['student_course_section']) ? trim((string) $validated['student_course_section']) : null,
                'suggested_priority' => $priority,
                'triage_response_target' => $priority->responseTarget(),
                'encrypted_concern_summary' => trim((string) $validated['concern_summary']),
                'status' => 'submitted',
            ]);

            ReferralAuditLog::create([
                'referral_id' => $referral->id,
                'actor_user_id' => $submitter->id,
                'action' => 'submitted',
                'ip_address' => $ipAddress,
            ]);

            return $referral;
        });
    }
}
