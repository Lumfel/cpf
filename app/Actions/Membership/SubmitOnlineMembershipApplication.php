<?php

namespace App\Actions\Membership;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusMembership;
use App\Models\User;

class SubmitOnlineMembershipApplication
{
    public function handle(User $user, array $validated, string $enrollmentSource = 'online_form'): CampusMembership
    {
        $first = trim((string) $validated['first_name']);
        $middle = isset($validated['middle_name']) ? trim((string) $validated['middle_name']) : null;
        $last = trim((string) $validated['last_name']);

        $course = trim((string) $validated['course']);
        $yearLevel = trim((string) $validated['year_level']);
        $section = trim((string) $validated['section']);
        $courseYearSection = CampusMembership::formatCourseYearSection($course, $yearLevel, $section);

        $fbUrl = trim((string) $validated['facebook_profile_url']);
        $fbDisplay = trim((string) ($validated['active_fb_account'] ?? ''));

        return CampusMembership::updateOrCreate(
            [
                'user_id' => $user->id,
                'campus_id' => (int) $validated['campus_id'],
            ],
            [
                'role' => CpfRole::Member,
                'status' => MembershipStatus::Pending,
                'enrollment_source' => $enrollmentSource,
                'recorded_email' => $validated['recorded_email'] ?? $user->email,
                'first_name' => $first,
                'middle_name' => $middle !== '' ? $middle : null,
                'last_name' => $last,
                'full_name_last_first_mi' => CampusMembership::formatLastFirstMi($first, $middle, $last),
                'course' => $course,
                'year_level' => $yearLevel,
                'section' => $section,
                'course_year_section' => $courseYearSection,
                'sex' => $validated['sex'],
                'gender_identity' => $validated['gender_identity'] ?? null,
                'gender_identity_other' => ($validated['gender_identity'] ?? null) === 'Others'
                    ? trim((string) ($validated['gender_identity_other'] ?? ''))
                    : null,
                'birthdate' => $validated['birthdate'],
                'active_email' => trim((string) $validated['active_email']),
                'facebook_profile_url' => $fbUrl,
                'active_fb_account' => $fbDisplay !== '' ? $fbDisplay : $fbUrl,
                'cellphone_number' => trim((string) $validated['cellphone_number']),
            ],
        );
    }
}
