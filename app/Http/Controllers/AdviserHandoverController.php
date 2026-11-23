<?php

namespace App\Http\Controllers;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\AdviserHandover;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdviserHandoverController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership !== null,
            403,
            'Only the campus Club Adviser can initiate an Adviser role handover.',
        );

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'handover_notes' => ['nullable', 'string', 'max:1000'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'sex' => ['required', Rule::in(['Male', 'Female'])],
            'cellphone_number' => ['nullable', 'string', 'max:30'],
            'initial_password' => ['required', 'string', 'min:8'],
        ]);

        abort_unless(
            Hash::check($validated['current_password'], (string) $user->password),
            422,
            'Current Adviser password is incorrect.',
        );

        $fullName = CampusMembership::formatLastFirstMi(
            (string) $validated['first_name'],
            $validated['middle_name'] ?? null,
            (string) $validated['last_name'],
        );
        $email = strtolower(trim((string) $validated['email']));
        $phone = trim((string) ($validated['cellphone_number'] ?? ''));

        $targetMembership = DB::transaction(function () use ($validated, $fullName, $email, $phone, $membership, $user): CampusMembership {
            $incomingUser = new User([
                'name' => $fullName,
                'email' => $email,
                'password' => Hash::make((string) $validated['initial_password']),
                'must_change_password' => true,
                'temporary_password_issued_at' => now(),
            ]);
            $incomingUser->email_verified_at = now();
            $incomingUser->save();

            return CampusMembership::create([
                'user_id' => $incomingUser->id,
                'campus_id' => $membership->campus_id,
                'role' => CpfRole::Member,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $email,
                'first_name' => trim((string) $validated['first_name']),
                'middle_name' => isset($validated['middle_name']) ? trim((string) $validated['middle_name']) : null,
                'last_name' => trim((string) $validated['last_name']),
                'full_name_last_first_mi' => $fullName,
                'course' => 'Guidance Services',
                'year_level' => 'Faculty',
                'section' => 'Incoming Adviser',
                'course_year_section' => 'Guidance Services / Incoming Adviser',
                'sex' => $validated['sex'],
                'gender_identity' => $validated['sex'] === 'Male' ? 'Man' : 'Woman',
                'birthdate' => '1985-01-01',
                'active_email' => $email,
                'facebook_profile_url' => 'https://www.facebook.com/chmsu.cpf.adviser',
                'active_fb_account' => $fullName,
                'cellphone_number' => $phone !== '' ? $phone : '0917-000-0000',
                'added_or_approved_by' => $user->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $user->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $user->id,
                'dues_confirmed_at' => now(),
            ])->load('user:id,name,email');
        });

        $confirmationCode = $this->generateVerificationCode();

        $handover = DB::transaction(function () use ($membership, $user, $targetMembership, $confirmationCode, $validated): AdviserHandover {
            $pendingHandovers = AdviserHandover::query()
                ->where('campus_id', $membership->campus_id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($pendingHandovers as $superseded) {
                $superseded->update([
                    'status' => 'cancelled',
                    'responded_at' => now(),
                ]);
                $this->revokePendingIncomingAdviserMembership($superseded);
            }

            return AdviserHandover::create([
                'campus_id' => $membership->campus_id,
                'from_user_id' => $user->id,
                'to_user_id' => $targetMembership->user_id,
                'to_membership_id' => $targetMembership->id,
                'status' => 'pending',
                'outgoing_role' => CpfRole::Member->value,
                'confirmation_code' => $confirmationCode,
                'handover_notes' => isset($validated['handover_notes']) && filled($validated['handover_notes'])
                    ? trim((string) $validated['handover_notes'])
                    : null,
                'initiated_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);
        });

        return response()->json([
            'status' => 'pending',
            'handover' => [
                'id' => $handover->id,
                'confirmation_code' => $handover->confirmation_code,
                'to_name' => $targetMembership->full_name_last_first_mi,
                'to_email' => $targetMembership->user?->email ?? $targetMembership->active_email,
                'expires_at' => $handover->expires_at->toDayDateTimeString(),
            ],
        ], 201);
    }

    public function respond(Request $request, AdviserHandover $handover): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $handover->to_user_id === $user->id,
            403,
            'Only the designated incoming Club Adviser can accept or decline this handover.',
        );

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
            'confirmation_code' => ['required_if:decision,accepted', 'nullable', 'string'],
        ]);

        $enteredCode = strtoupper(trim((string) ($validated['confirmation_code'] ?? '')));

        $resultStatus = DB::transaction(function () use ($handover, $validated, $enteredCode): string {
            $lockedHandover = AdviserHandover::query()
                ->whereKey($handover->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedHandover->isPendingAndActive(),
                422,
                'This Adviser handover is no longer active or has expired.',
            );

            if ($validated['decision'] === 'declined') {
                $lockedHandover->update([
                    'status' => 'declined',
                    'responded_at' => now(),
                ]);

                $this->revokePendingIncomingAdviserMembership($lockedHandover);

                return 'declined';
            }

            abort_unless(
                hash_equals(strtoupper($lockedHandover->confirmation_code), $enteredCode),
                422,
                'Invalid 6-character handover verification code.',
            );

            $incomingMembership = $lockedHandover->toMembership()->lockForUpdate()->firstOrFail();
            $incomingMembership->update([
                'role' => CpfRole::Adviser,
                'status' => MembershipStatus::Approved,
                'section' => 'Adviser',
                'course_year_section' => 'Guidance Services / Adviser',
            ]);

            $outgoingMembership = CampusMembership::query()
                ->where('campus_id', $lockedHandover->campus_id)
                ->where('user_id', $lockedHandover->from_user_id)
                ->lockForUpdate()
                ->first();

            if ($outgoingMembership) {
                $outgoingRole = CpfRole::tryFrom((string) $lockedHandover->outgoing_role) ?? CpfRole::Member;
                $outgoingMembership->update(['role' => $outgoingRole]);
            }

            Campus::query()
                ->where('id', $lockedHandover->campus_id)
                ->update(['adviser_name' => $incomingMembership->full_name_last_first_mi]);

            $lockedHandover->update([
                'status' => 'accepted',
                'responded_at' => now(),
            ]);

            return 'accepted';
        });

        return response()->json(['status' => $resultStatus]);
    }

    public function cancel(Request $request, AdviserHandover $handover): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user?->isClubAdviser() && $handover->from_user_id === $user->id,
            403,
            'Only the initiating Club Adviser can cancel this handover.',
        );

        DB::transaction(function () use ($handover): void {
            $lockedHandover = AdviserHandover::query()
                ->whereKey($handover->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedHandover->status === 'pending',
                422,
                'Only pending Adviser handovers can be cancelled.',
            );

            $lockedHandover->update([
                'status' => 'cancelled',
                'responded_at' => now(),
            ]);

            $this->revokePendingIncomingAdviserMembership($lockedHandover);
        });

        return response()->json(['status' => 'cancelled']);
    }

    private function revokePendingIncomingAdviserMembership(AdviserHandover $handover): void
    {
        $incomingMembership = $handover->toMembership()->lockForUpdate()->first();

        if (
            $incomingMembership !== null
            && $incomingMembership->section === 'Incoming Adviser'
            && $incomingMembership->role === CpfRole::Member
        ) {
            $incomingMembership->update([
                'status' => MembershipStatus::Rejected,
            ]);
        }
    }

    private function generateVerificationCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
