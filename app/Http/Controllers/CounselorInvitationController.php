<?php

namespace App\Http\Controllers;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusCounselor;
use App\Models\CampusMembership;
use App\Models\CounselorInvitation;
use App\Models\User;
use App\Notifications\CounselorInviteOtpNotification;
use App\Services\ClubTenureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CounselorInvitationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership()->with('campus:id,code,name')->first();

        abort_unless(
            $user?->isClubAdviser() && $membership !== null,
            403,
            'Only the campus Club Adviser can invite campus Guidance Counselors.',
        );

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'counselor_name' => ['nullable', 'string', 'max:180'],
        ]);

        $email = strtolower(trim((string) $validated['email']));
        $counselorName = isset($validated['counselor_name']) && filled($validated['counselor_name'])
            ? trim((string) $validated['counselor_name'])
            : null;

        abort_if(
            User::query()->where('email', $email)->exists(),
            422,
            'An account with this email already exists in CPF and cannot be invited as a new Guidance Counselor.',
        );

        $token = Str::random(48);
        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addHours(24);

        $invitation = DB::transaction(function () use ($membership, $user, $email, $counselorName, $token, $otpCode, $expiresAt): CounselorInvitation {
            CounselorInvitation::query()
                ->where('campus_id', $membership->campus_id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->update(['expires_at' => now()->subSecond()]);

            return CounselorInvitation::create([
                'campus_id' => $membership->campus_id,
                'invited_by_user_id' => $user->id,
                'email' => $email,
                'counselor_name' => $counselorName,
                'token' => $token,
                'otp_hash' => Hash::make($otpCode),
                'expires_at' => $expiresAt,
            ]);
        });

        $inviteUrl = URL::temporarySignedRoute(
            'counselor-invitations.show',
            $expiresAt,
            ['token' => $token],
        );

        $campusName = $membership->campus?->name ?? 'CHMSU Campus';
        $emailStatus = 'sent';

        try {
            $defaultMailer = (string) config('mail.default', 'log');
            $smtpPassword = (string) config('mail.mailers.smtp.password', '');
            if ($defaultMailer === 'smtp' && ($smtpPassword === '' || str_contains($smtpPassword, 'PASTE_YOUR_'))) {
                $emailStatus = 'logged';
            } else {
                Notification::route('mail', $email)->notify(
                    new CounselorInviteOtpNotification(
                        campusName: $campusName,
                        inviterName: $user->name,
                        inviteUrl: $inviteUrl,
                        otpCode: $otpCode,
                        expiresAtLabel: $expiresAt->toDayDateTimeString(),
                    ),
                );
            }
        } catch (\Throwable) {
            $emailStatus = 'failed';
        }

        $payload = [
            'status' => 'invited',
            'email_status' => $emailStatus,
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'counselor_name' => $invitation->counselor_name,
                'expires_at' => $invitation->expires_at->toDayDateTimeString(),
            ],
        ];

        if (app()->isLocal()) {
            $payload['invite_url'] = $inviteUrl;
            $payload['otp_code'] = $otpCode;
        }

        return response()->json($payload, 201);
    }

    public function show(Request $request, string $token): Response
    {
        abort_unless(
            $request->hasValidSignature(),
            403,
            'This Guidance Counselor invitation link is invalid or has expired.',
        );

        $invitation = CounselorInvitation::query()
            ->with(['campus:id,code,name', 'inviter:id,name'])
            ->where('token', $token)
            ->firstOrFail();

        abort_unless(
            $invitation->isPendingAndActive(),
            410,
            'This Guidance Counselor invitation has already been accepted or has expired.',
        );

        $acceptUrl = URL::temporarySignedRoute(
            'counselor-invitations.accept',
            $invitation->expires_at,
            ['token' => $invitation->token],
        );

        return Inertia::render('auth/AcceptCounselorInvite', [
            'acceptUrl' => $acceptUrl,
            'email' => $invitation->email,
            'counselorName' => $invitation->counselor_name,
            'campusName' => $invitation->campus?->name ?? 'CHMSU Campus',
            'inviterName' => $invitation->inviter?->name ?? 'Campus Club Adviser',
            'expiresAt' => $invitation->expires_at->toDayDateTimeString(),
        ]);
    }

    public function accept(
        Request $request,
        string $token,
        ClubTenureService $tenureService,
    ): JsonResponse|RedirectResponse {
        abort_unless(
            $request->hasValidSignature(),
            403,
            'This Guidance Counselor invitation link is invalid or has expired.',
        );

        $validated = $request->validate([
            'otp_code' => ['required', 'digits:6'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:100'],
            'sex' => ['required', Rule::in(['Male', 'Female'])],
            'cellphone_number' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:160'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $counselorUser = DB::transaction(function () use ($token, $validated, $tenureService): User {
            $invitation = CounselorInvitation::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $invitation->isPendingAndActive(),
                422,
                'This Guidance Counselor invitation has already been accepted or has expired.',
            );

            $inviterStillAdviser = CampusMembership::query()
                ->where('user_id', $invitation->invited_by_user_id)
                ->where('campus_id', $invitation->campus_id)
                ->where('role', CpfRole::Adviser->value)
                ->where('status', MembershipStatus::Approved->value)
                ->exists();

            abort_unless(
                $inviterStillAdviser,
                403,
                'This invitation is no longer valid because the inviting Club Adviser is no longer active on this campus.',
            );

            if (! Hash::check((string) $validated['otp_code'], $invitation->otp_hash)) {
                throw ValidationException::withMessages([
                    'otp_code' => 'Invalid 6-digit OTP verification code.',
                ]);
            }

            $fullName = CampusMembership::formatLastFirstMi(
                (string) $validated['first_name'],
                $validated['middle_name'] ?? null,
                (string) $validated['last_name'],
            );
            $phone = trim((string) ($validated['cellphone_number'] ?? ''));

            abort_if(
                User::query()->where('email', $invitation->email)->lockForUpdate()->exists(),
                422,
                'An account with this email already exists and cannot be overwritten via counselor invitation.',
            );

            $user = new User([
                'name' => $fullName,
                'email' => $invitation->email,
                'password' => Hash::make((string) $validated['password']),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ]);
            $user->email_verified_at = now();
            $user->save();

            $membership = CampusMembership::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'campus_id' => $invitation->campus_id,
                ],
                [
                    'role' => CpfRole::GuidanceCounselor,
                    'status' => MembershipStatus::Approved,
                    'enrollment_source' => 'officer_added',
                    'recorded_email' => $invitation->email,
                    'first_name' => trim((string) $validated['first_name']),
                    'middle_name' => isset($validated['middle_name']) && filled($validated['middle_name'])
                        ? trim((string) $validated['middle_name'])
                        : null,
                    'last_name' => trim((string) $validated['last_name']),
                    'full_name_last_first_mi' => $fullName,
                    'course' => 'Guidance Services',
                    'year_level' => 'Faculty',
                    'section' => 'Guidance Counselor',
                    'course_year_section' => 'Guidance Services / Guidance Counselor',
                    'sex' => $validated['sex'],
                    'gender_identity' => $validated['sex'] === 'Male' ? 'Man' : 'Woman',
                    'birthdate' => '1985-01-01',
                    'active_email' => $invitation->email,
                    'facebook_profile_url' => 'https://www.facebook.com/chmsu.guidance.counselor',
                    'active_fb_account' => $fullName,
                    'cellphone_number' => $phone !== '' ? $phone : '0917-000-0000',
                    'added_or_approved_by' => $invitation->invited_by_user_id,
                    'approved_at' => now(),
                    'is_in_messenger_gc' => true,
                    'gc_confirmed_by' => $invitation->invited_by_user_id,
                    'gc_confirmed_at' => now(),
                    'has_paid_dues' => true,
                    'dues_confirmed_by' => $invitation->invited_by_user_id,
                    'dues_confirmed_at' => now(),
                ],
            );

            CampusCounselor::query()->firstOrCreate(
                [
                    'campus_id' => $invitation->campus_id,
                    'name' => $fullName,
                ],
                [
                    'title' => 'Registered Guidance Counselor',
                    'specialty' => isset($validated['specialty']) && filled($validated['specialty'])
                        ? trim((string) $validated['specialty'])
                        : 'Student Wellness & Peer Referral Triage',
                    'weekly_vacant_slots' => [
                        ['day' => 'Monday', 'start' => '09:00', 'end' => '11:30'],
                        ['day' => 'Wednesday', 'start' => '13:30', 'end' => '16:00'],
                        ['day' => 'Friday', 'start' => '09:00', 'end' => '11:30'],
                    ],
                    'is_active' => true,
                ],
            );

            $tenureService->openTenure($membership->fresh(), $user);

            $invitation->update([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ]);

            return $user;
        });

        Auth::login($counselorUser);
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'registered',
                'role' => CpfRole::GuidanceCounselor->value,
                'redirect' => route('campus.operations'),
            ], 201);
        }

        return redirect()->route('campus.operations')->with(
            'status',
            'Welcome! Your Guidance Counselor coordination account is now active.',
        );
    }
}
