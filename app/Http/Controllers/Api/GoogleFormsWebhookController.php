<?php

namespace App\Http\Controllers\Api;

use App\Actions\Membership\SubmitOnlineMembershipApplication;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class GoogleFormsWebhookController extends Controller
{
    public function health(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json([
                'message' => 'Unauthorized Google Forms integration request.',
            ], 401);
        }

        $activeCampus = Campus::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first(['id', 'code', 'name']);

        return response()->json([
            'ok' => true,
            'active_campus_id' => $activeCampus?->id,
            'active_campus_code' => $activeCampus?->code,
            'active_campus_name' => $activeCampus?->name,
            'endpoint' => url('/api/google-forms/membership'),
        ]);
    }

    public function store(Request $request, SubmitOnlineMembershipApplication $action): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json([
                'message' => 'Unauthorized Google Forms integration request.',
            ], 401);
        }

        $payload = $request->all();
        $campusId = $payload['campus_id'] ?? null;

        if (! $campusId && ! empty($payload['campus_code'])) {
            $campus = Campus::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(code) = ?', [strtolower(trim((string) $payload['campus_code']))])
                ->first();
            $campusId = $campus?->id;
        }

        if (! $campusId) {
            $campusId = Campus::query()->where('is_active', true)->orderBy('id')->value('id');
        }

        ['gender_identity' => $genderIdentity, 'gender_identity_other' => $genderIdentityOther] =
            $this->normalizeGenderIdentity(
                $payload['gender_identity'] ?? null,
                $payload['gender_identity_other'] ?? null,
            );

        $normalized = [
            'campus_id' => $campusId,
            'recorded_email' => $payload['recorded_email'] ?? $payload['active_email'] ?? null,
            'first_name' => trim((string) ($payload['first_name'] ?? '')),
            'middle_name' => isset($payload['middle_name']) ? trim((string) $payload['middle_name']) : null,
            'last_name' => trim((string) ($payload['last_name'] ?? '')),
            'course' => trim((string) ($payload['course'] ?? '')),
            'year_level' => $this->normalizeYearLevel($payload['year_level'] ?? ''),
            'section' => trim((string) ($payload['section'] ?? '')),
            'sex' => $this->normalizeSex($payload['sex'] ?? ''),
            'gender_identity' => $genderIdentity,
            'gender_identity_other' => $genderIdentityOther,
            'birthdate' => $this->normalizeBirthdate($payload['birthdate'] ?? ''),
            'active_email' => trim((string) ($payload['active_email'] ?? '')),
            'facebook_profile_url' => $this->normalizeFacebookUrl($payload['facebook_profile_url'] ?? ''),
            'active_fb_account' => isset($payload['active_fb_account']) ? trim((string) $payload['active_fb_account']) : null,
            'cellphone_number' => trim((string) ($payload['cellphone_number'] ?? '')),
        ];

        $validator = Validator::make($normalized, [
            'campus_id' => ['required', 'integer', Rule::exists('campuses', 'id')->where('is_active', true)],
            'recorded_email' => ['nullable', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'course' => ['required', 'string', 'max:60'],
            'year_level' => ['required', 'string', 'max:20'],
            'section' => ['required', 'string', 'max:20'],
            'sex' => ['required', Rule::in(['Male', 'Female'])],
            'gender_identity' => ['nullable', Rule::in(['Man', 'Woman', 'Non-binary', 'Prefer not to say', 'Others'])],
            'gender_identity_other' => ['nullable', 'string', 'max:100'],
            'birthdate' => ['required', 'date', 'before:today'],
            'active_email' => ['required', 'email', 'max:255'],
            'facebook_profile_url' => [
                'required',
                'url',
                'max:500',
                'regex:/^https?:\/\/(www\.|m\.|web\.|l\.)?(facebook\.com|fb\.com)\/.+/i',
            ],
            'active_fb_account' => ['nullable', 'string', 'max:255'],
            'cellphone_number' => ['required', 'string', 'max:40'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Google Form submission validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $email = strtolower(trim((string) $validated['active_email']));
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser && $existingUser->primaryMembership?->status === MembershipStatus::Approved) {
            return response()->json([
                'message' => 'This email already belongs to an approved CPF member.',
            ], 422);
        }

        $targetUser = $existingUser ?? User::create([
            'name' => CampusMembership::formatLastFirstMi(
                (string) $validated['first_name'],
                $validated['middle_name'] ?? null,
                (string) $validated['last_name'],
            ),
            'email' => $email,
            'password' => Hash::make(Str::password(16)),
            'email_verified_at' => now(),
            'must_change_password' => false,
        ]);

        $membership = $action->handle($targetUser, $validated, 'google_forms_api');

        return response()->json([
            'message' => 'Google Form application recorded for Club Adviser review and confirmation.',
            'membership_id' => $membership->id,
            'status' => $membership->status->value,
            'enrollment_source' => $membership->enrollment_source,
            'full_name' => $membership->full_name_last_first_mi,
            'course_year_section' => $membership->course_year_section,
        ], 201);
    }

    private function isAuthorized(Request $request): bool
    {
        $configuredSecret = (string) config('cpf.google_forms.webhook_secret');
        $providedSecret = (string) $request->header('X-CPF-Forms-Secret', '');

        return $configuredSecret !== '' && hash_equals($configuredSecret, $providedSecret);
    }

    private function normalizeYearLevel(mixed $value): string
    {
        $trimmed = trim((string) $value);
        if (preg_match('/^([1-5])(?:st|nd|rd|th)?(?:\s*year)?$/i', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }

    private function normalizeSex(mixed $value): string
    {
        $trimmed = trim((string) $value);

        return match (strtolower($trimmed)) {
            'male' => 'Male',
            'female' => 'Female',
            default => $trimmed,
        };
    }

    /**
     * @return array{gender_identity: string|null, gender_identity_other: string|null}
     */
    private function normalizeGenderIdentity(mixed $rawIdentity, mixed $rawOther): array
    {
        $trimmed = trim((string) ($rawIdentity ?? ''));
        $trimmedOther = trim((string) ($rawOther ?? ''));

        if ($trimmed === '') {
            return [
                'gender_identity' => null,
                'gender_identity_other' => $trimmedOther !== '' ? $trimmedOther : null,
            ];
        }

        $canonicalMap = [
            'man' => 'Man',
            'woman' => 'Woman',
            'non-binary' => 'Non-binary',
            'prefer not to say' => 'Prefer not to say',
            'others' => 'Others',
        ];

        $lower = strtolower($trimmed);
        if (isset($canonicalMap[$lower])) {
            return [
                'gender_identity' => $canonicalMap[$lower],
                'gender_identity_other' => $trimmedOther !== '' ? $trimmedOther : null,
            ];
        }

        return [
            'gender_identity' => 'Others',
            'gender_identity_other' => $trimmedOther !== '' ? $trimmedOther : $trimmed,
        ];
    }

    private function normalizeFacebookUrl(mixed $value): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^(?:www\.|m\.|web\.|l\.)?(?:facebook\.com|fb\.com)\/.+/i', $trimmed) === 1) {
            return 'https://'.$trimmed;
        }

        return $trimmed;
    }

    private function normalizeBirthdate(mixed $value): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return '';
        }

        try {
            return Carbon::parse($trimmed)->format('Y-m-d');
        } catch (Throwable) {
            return $trimmed;
        }
    }
}
