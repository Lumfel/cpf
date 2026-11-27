<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\PasswordValidationRules;
use App\Enums\CpfRole;
use App\Http\Controllers\Auth\PasskeyCodeLoginController;
use App\Http\Controllers\Controller;
use App\Models\PeerQuote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use PasswordValidationRules;

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $email = strtolower(trim((string) $validated['email']));
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check((string) $validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $twoFactor = PasskeyCodeLoginController::verifyTwoFactorChallenge(
                $user,
                $validated['code'] ?? null,
                $validated['recovery_code'] ?? null,
            );

            if (! $twoFactor['valid']) {
                return response()->json([
                    'message' => $twoFactor['missing']
                        ? 'Two-factor authentication code is required.'
                        : 'The provided two-factor authentication code was invalid.',
                    'two_factor_required' => true,
                    'errors' => [
                        'code' => [
                            $twoFactor['missing']
                                ? 'A valid two-factor authentication code or recovery code is required.'
                                : 'The provided two-factor authentication code was invalid.',
                        ],
                    ],
                ], 422);
            }
        }

        $token = $user->createToken(trim((string) $validated['device_name']))->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $token,
            ...$this->buildProfilePayload($user->fresh()),
        ]);
    }

    public function passkeyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'passkey_code' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user = PasskeyCodeLoginController::claimPasskeyCode(
            (string) $validated['email'],
            (string) $validated['passkey_code'],
            $validated['code'] ?? null,
            $validated['recovery_code'] ?? null,
            jsonTwoFactorFailure: true,
        );

        $token = $user->createToken(trim((string) $validated['device_name']))->plainTextToken;

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $token,
            ...$this->buildProfilePayload($user),
        ]);
    }

    public function forceChangePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'password' => $this->passwordRules(),
        ]);

        $user->forceFill([
            'password' => Hash::make((string) $validated['password']),
            'must_change_password' => false,
            'temporary_password_issued_at' => null,
        ])->save();

        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId !== null, fn ($query) => $query->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json($this->buildProfilePayload($user->fresh()));
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->buildProfilePayload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'status' => 'logged_out',
            'message' => 'Token revoked.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfilePayload(User $user): array
    {
        $membership = $user->primaryMembership()
            ->with([
                'campus:id,code,name,slug,current_academic_year,gc_group_name',
                'tenures:id,campus_membership_id,role,started_at',
            ])
            ->first();

        $user->setRelation('primaryMembership', $membership);

        $campus = $membership?->campus;
        $campusId = $campus?->id;
        $campusCode = $campus?->code ?? 'CHMSU';
        $verifiedRole = $user->verifiedRole();
        $isAlumni = $user->isAlumni();

        $qrUnlocked = ! $isAlumni
            && $verifiedRole !== CpfRole::GuidanceCounselor
            && $user->must_change_password === false
            && $verifiedRole !== null;

        $qrPayload = null;
        $memberIdCode = null;

        if ($qrUnlocked) {
            $rawPayload = "CPF|campus:{$campusCode}|user:{$user->id}|role:{$verifiedRole->value}";
            $signature = substr(hash_hmac('sha256', $rawPayload, (string) config('app.key')), 0, 16);
            $qrPayload = "{$rawPayload}|sig:{$signature}";
            $memberIdCode = sprintf('CPF-%s-%s-%04d', $campusCode, now()->format('Y'), $user->id);
        }

        $approvedQuotes = PeerQuote::query()
            ->where('is_approved', true)
            ->when($campusId !== null, function ($query) use ($campusId): void {
                $query->where(function ($q) use ($campusId): void {
                    $q->whereNull('campus_id')->orWhere('campus_id', $campusId);
                });
            })
            ->orderBy('id')
            ->get(['id', 'quote_text', 'author_name', 'mood_tag']);

        $dailyQuote = $approvedQuotes->isNotEmpty()
            ? $approvedQuotes->values()->get(now()->dayOfYear % $approvedQuotes->count())->toArray()
            : [
                'id' => 0,
                'quote_text' => 'Take a breath. Your presence in this community matters more than perfection.',
                'author_name' => 'Circle of Peer Facilitators',
                'mood_tag' => 'Calm',
            ];

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar,
                'must_change_password' => (bool) $user->must_change_password,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
            'verified_role' => $verifiedRole?->value,
            'verified_role_label' => $verifiedRole?->label() ?? $user->formerRoleLabel() ?? 'Ordinary Account',
            'privileges_suspended' => $user->hasSuspendedOfficerPrivileges(),
            'is_alumni' => $isAlumni,
            'former_role' => $user->formerRole()?->value,
            'former_role_label' => $user->formerRoleLabel(),
            'permissions' => [
                'is_officer_or_adviser' => $user->isActiveOfficerOrAdviser(),
                'is_club_adviser' => $user->isClubAdviser(),
                'can_propose_events' => $user->canProposeEvents(),
                'can_scan_attendance' => $user->isActiveOfficerOrAdviser(),
                'can_access_archive' => $user->canAccessAlumniArchive(),
                'privileges_suspended' => $user->hasSuspendedOfficerPrivileges(),
            ],
            'campus' => $campus ? [
                'id' => $campus->id,
                'code' => $campus->code,
                'name' => $campus->name,
                'slug' => $campus->slug,
                'current_academic_year' => $campus->current_academic_year,
                'gc_group_name' => $campus->gc_group_name,
            ] : null,
            'membership' => $membership ? [
                'id' => $membership->id,
                'status' => $membership->status->value,
                'full_name_last_first_mi' => $membership->full_name_last_first_mi,
                'course' => $membership->course,
                'year_level' => $membership->year_level,
                'section' => $membership->section,
                'course_year_section' => $membership->course_year_section,
                'is_in_messenger_gc' => (bool) $membership->is_in_messenger_gc,
                'has_paid_dues' => (bool) $membership->has_paid_dues,
            ] : null,
            'qr_unlocked' => $qrUnlocked,
            'member_id_code' => $memberIdCode,
            'qr_payload' => $qrPayload,
            'daily_quote' => $dailyQuote,
        ];
    }
}
