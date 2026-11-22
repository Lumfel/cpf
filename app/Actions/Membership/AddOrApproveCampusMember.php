<?php

namespace App\Actions\Membership;

use App\Actions\Security\IssueMemberPasskey;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusMembership;
use App\Models\PasskeyIssuance;
use App\Models\User;
use App\Notifications\MembershipApprovedPasskeyNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class AddOrApproveCampusMember
{
    public function __construct(
        private readonly IssueMemberPasskey $issuePasskey,
    ) {}

    /**
     * @return array{membership: CampusMembership, passkey_code: string|null, emailed: bool, email_status: string, email_error: string|null}
     */
    public function addDirectly(User $actor, array $validated): array
    {
        $actorMembership = $actor->primaryMembership;
        $campusId = $actorMembership ? $actorMembership->campus_id : (int) $validated['campus_id'];

        $requestedRole = isset($validated['role']) ? CpfRole::from((string) $validated['role']) : CpfRole::Member;
        $assignedRole = $actor->isClubAdviser() ? $requestedRole : CpfRole::Member;

        $first = trim((string) $validated['first_name']);
        $middle = isset($validated['middle_name']) ? trim((string) $validated['middle_name']) : null;
        $last = trim((string) $validated['last_name']);
        $formatted = CampusMembership::formatLastFirstMi($first, $middle, $last);

        $course = trim((string) $validated['course']);
        $yearLevel = trim((string) $validated['year_level']);
        $section = trim((string) $validated['section']);
        $courseYearSection = CampusMembership::formatCourseYearSection($course, $yearLevel, $section);

        $fbUrl = trim((string) $validated['facebook_profile_url']);
        $fbDisplay = trim((string) ($validated['active_fb_account'] ?? ''));

        $tx = DB::transaction(function () use (
            $actor,
            $validated,
            $campusId,
            $assignedRole,
            $first,
            $middle,
            $last,
            $formatted,
            $course,
            $yearLevel,
            $section,
            $courseYearSection,
            $fbUrl,
            $fbDisplay,
        ): array {
            $email = strtolower(trim((string) $validated['active_email']));
            $targetUser = User::query()->where('email', $email)->first();

            if (! $targetUser) {
                $targetUser = new User([
                    'name' => $formatted,
                    'email' => $email,
                    'password' => Hash::make(Str::password(24)),
                    'must_change_password' => true,
                    'temporary_password_issued_at' => now(),
                ]);
                $targetUser->email_verified_at = now();
                $targetUser->save();
            } else {
                $hasApprovedMembership = CampusMembership::query()
                    ->where('user_id', $targetUser->id)
                    ->where('status', MembershipStatus::Approved->value)
                    ->exists();

                if (! $hasApprovedMembership) {
                    $targetUser->forceFill([
                        'name' => $formatted,
                        'email_verified_at' => $targetUser->email_verified_at ?? now(),
                        'must_change_password' => true,
                        'temporary_password_issued_at' => now(),
                    ])->save();
                }
            }

            $membership = CampusMembership::updateOrCreate(
                [
                    'user_id' => $targetUser->id,
                    'campus_id' => $campusId,
                ],
                [
                    'role' => $assignedRole,
                    'status' => MembershipStatus::Approved,
                    'enrollment_source' => 'officer_added',
                    'recorded_email' => $email,
                    'first_name' => $first,
                    'middle_name' => $middle !== '' ? $middle : null,
                    'last_name' => $last,
                    'full_name_last_first_mi' => $formatted,
                    'course' => $course,
                    'year_level' => $yearLevel,
                    'section' => $section,
                    'course_year_section' => $courseYearSection,
                    'sex' => $validated['sex'],
                    'gender_identity' => $validated['gender_identity'] ?? 'Prefer not to say',
                    'gender_identity_other' => ($validated['gender_identity'] ?? null) === 'Others'
                        ? trim((string) ($validated['gender_identity_other'] ?? ''))
                        : null,
                    'birthdate' => $validated['birthdate'],
                    'active_email' => $email,
                    'facebook_profile_url' => $fbUrl,
                    'active_fb_account' => $fbDisplay !== '' ? $fbDisplay : $fbUrl,
                    'cellphone_number' => trim((string) $validated['cellphone_number']),
                    'added_or_approved_by' => $actor->id,
                    'approved_at' => now(),
                ],
            );

            $passkeyResult = $this->issuePasskey->handle($actor, $membership, true, false);

            return [
                'membership' => $membership,
                'issuance' => $passkeyResult['issuance'],
                'passkey_code' => $passkeyResult['enrollment_code'],
            ];
        });

        $delivery = $this->deliverApprovalPasskeyOnce(
            $tx['membership']->loadMissing('campus', 'user'),
            $tx['issuance'],
            $tx['passkey_code'],
        );

        return [
            'membership' => $tx['membership'],
            'passkey_code' => $tx['passkey_code'],
            'emailed' => $delivery['emailed'],
            'email_status' => $delivery['email_status'],
            'email_error' => $delivery['email_error'],
        ];
    }

    /**
     * @return array{membership: CampusMembership, passkey_code: string, emailed: bool, email_status: string, email_error: string|null}
     */
    public function approveApplication(User $actor, CampusMembership $membership, ?string $remarks = null): array
    {
        $tx = DB::transaction(function () use ($actor, $membership, $remarks): array {
            $membership->update([
                'status' => MembershipStatus::Approved,
                'added_or_approved_by' => $actor->id,
                'approved_at' => now(),
                'remarks' => $remarks ?? $membership->remarks,
            ]);

            $memberUser = $membership->user;
            if ($memberUser) {
                $memberUser->forceFill([
                    'email_verified_at' => $memberUser->email_verified_at ?? now(),
                    'must_change_password' => true,
                    'temporary_password_issued_at' => now(),
                ])->save();
            }

            $passkeyResult = $this->issuePasskey->handle($actor, $membership, true, false);

            return [
                'membership' => $membership->refresh(),
                'issuance' => $passkeyResult['issuance'],
                'passkey_code' => $passkeyResult['enrollment_code'],
            ];
        });

        $refreshed = $tx['membership']->loadMissing('campus', 'user');
        $delivery = $this->deliverApprovalPasskeyOnce(
            $refreshed,
            $tx['issuance'],
            $tx['passkey_code'],
        );

        return [
            'membership' => $refreshed,
            'passkey_code' => $tx['passkey_code'],
            'emailed' => $delivery['emailed'],
            'email_status' => $delivery['email_status'],
            'email_error' => $delivery['email_error'],
        ];
    }

    /**
     * Deliver the member's locked 12-character passkey code to their email strictly one time only.
     *
     * @return array{emailed: bool, email_status: string, email_error: string|null}
     */
    private function deliverApprovalPasskeyOnce(
        CampusMembership $membership,
        PasskeyIssuance $issuance,
        string $plainCode,
    ): array {
        $alreadyEmailed = $issuance->emailed_at !== null
            || PasskeyIssuance::query()
                ->where('campus_id', $membership->campus_id)
                ->where('target_user_id', $membership->user_id)
                ->whereNotNull('emailed_at')
                ->exists();

        if ($alreadyEmailed) {
            return [
                'emailed' => false,
                'email_status' => 'already_sent',
                'email_error' => null,
            ];
        }

        $recipientEmail = strtolower(trim((string) ($membership->active_email ?: $membership->user?->email)));

        if ($recipientEmail === '') {
            return [
                'emailed' => false,
                'email_status' => 'failed',
                'email_error' => 'No valid recipient email address found on this membership.',
            ];
        }

        $mailer = (string) config('mail.default', 'log');

        if ($mailer === 'smtp') {
            $smtpUsername = trim((string) config('mail.mailers.smtp.username'));
            $smtpPassword = trim((string) config('mail.mailers.smtp.password'));

            if (
                $smtpPassword === ''
                || strtolower($smtpPassword) === 'null'
                || $smtpPassword === 'PASTE_YOUR_16_CHAR_GOOGLE_APP_PASSWORD_HERE'
                || $smtpPassword === 'YOUR_MAILTRAP_PASSWORD'
                || $smtpUsername === 'YOUR_MAILTRAP_USERNAME'
            ) {
                return [
                    'emailed' => false,
                    'email_status' => 'failed',
                    'email_error' => 'SMTP credentials are not configured in .env yet. Paste your Mailtrap (or SMTP) MAIL_USERNAME and MAIL_PASSWORD, run php artisan config:clear, and restart php artisan serve.',
                ];
            }
        }

        try {
            Notification::route('mail', $recipientEmail)->notify(
                new MembershipApprovedPasskeyNotification(
                    memberName: $membership->full_name_last_first_mi,
                    recipientEmail: $recipientEmail,
                    campusName: $membership->campus?->name ?? 'CHMSU Campus Chapter',
                    roleLabel: $membership->role->label(),
                    passkeyCode: $plainCode,
                ),
            );

            if ($mailer === 'log') {
                return [
                    'emailed' => false,
                    'email_status' => 'logged',
                    'email_error' => 'MAIL_MAILER is set to log — email was written to storage/logs/laravel.log instead of Gmail.',
                ];
            }

            $issuance->forceFill([
                'emailed_at' => now(),
            ])->save();

            return [
                'emailed' => true,
                'email_status' => 'sent',
                'email_error' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to deliver one-time approval passkey email.', [
                'membership_id' => $membership->id,
                'recipient_email' => $recipientEmail,
                'error' => $e->getMessage(),
            ]);

            return [
                'emailed' => false,
                'email_status' => 'failed',
                'email_error' => $e->getMessage(),
            ];
        }
    }
}
