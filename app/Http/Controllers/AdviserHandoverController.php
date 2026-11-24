<?php

namespace App\Http\Controllers;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\AdviserHandover;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\User;
use App\Services\ClubTenureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdviserHandoverController extends Controller
{
    public function __construct(
        private readonly ClubTenureService $tenureService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership !== null,
            403,
            'Only the campus Club Adviser can initiate an Adviser role handover.',
        );

        $mode = (string) $request->input('mode', '');
        if ($mode === '' && $request->filled('target_membership_id') && ! $request->filled('first_name')) {
            $mode = 'existing_counselor';
        } elseif ($mode === '') {
            $mode = 'invite_new';
        }

        abort_unless(
            in_array($mode, ['existing_counselor', 'invite_new'], true),
            422,
            'Invalid Adviser handover mode. Select an existing campus Guidance Counselor or invite a new Counselor.',
        );

        if ($mode === 'existing_counselor') {
            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'handover_notes' => ['nullable', 'string', 'max:1000'],
                'target_membership_id' => ['required', 'integer', 'exists:campus_memberships,id'],
            ]);
        } else {
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
        }

        abort_unless(
            Hash::check($validated['current_password'], (string) $user->password),
            422,
            'Current Adviser password is incorrect.',
        );

        $confirmationCode = $this->generateVerificationCode();

        [$handover, $targetMembership] = DB::transaction(function () use ($mode, $validated, $membership, $user, $confirmationCode): array {
            Campus::query()
                ->whereKey($membership->campus_id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeAdviserMembership = CampusMembership::query()
                ->where('campus_id', $membership->campus_id)
                ->where('user_id', $user->id)
                ->where('role', CpfRole::Adviser->value)
                ->where('status', MembershipStatus::Approved->value)
                ->lockForUpdate()
                ->first();

            abort_unless(
                $activeAdviserMembership !== null,
                403,
                'Only the active campus Club Adviser can initiate an Adviser role handover.',
            );

            $this->revokeExpiredHandoversForCampus($membership->campus_id);

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

            if ($mode === 'existing_counselor') {
                $targetMembership = CampusMembership::query()
                    ->with('user:id,name,email')
                    ->whereKey($validated['target_membership_id'])
                    ->lockForUpdate()
                    ->first();

                abort_unless(
                    $targetMembership !== null
                    && $targetMembership->campus_id === $membership->campus_id
                    && $targetMembership->status === MembershipStatus::Approved
                    && $targetMembership->role === CpfRole::GuidanceCounselor
                    && $targetMembership->user_id !== $user->id,
                    422,
                    'Only an existing approved Guidance Counselor on your campus can be selected for Adviser handover.',
                );
            } else {
                $fullName = CampusMembership::formatLastFirstMi(
                    (string) $validated['first_name'],
                    $validated['middle_name'] ?? null,
                    (string) $validated['last_name'],
                );
                $email = strtolower(trim((string) $validated['email']));
                $phone = trim((string) ($validated['cellphone_number'] ?? ''));

                $incomingUser = new User([
                    'name' => $fullName,
                    'email' => $email,
                    'password' => Hash::make((string) $validated['initial_password']),
                    'must_change_password' => true,
                    'temporary_password_issued_at' => now(),
                ]);
                $incomingUser->email_verified_at = now();
                $incomingUser->save();

                $targetMembership = CampusMembership::create([
                    'user_id' => $incomingUser->id,
                    'campus_id' => $membership->campus_id,
                    'role' => CpfRole::GuidanceCounselor,
                    'status' => MembershipStatus::Pending,
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
                    'approved_at' => null,
                    'is_in_messenger_gc' => false,
                    'gc_confirmed_by' => null,
                    'gc_confirmed_at' => null,
                    'has_paid_dues' => false,
                    'dues_confirmed_by' => null,
                    'dues_confirmed_at' => null,
                ])->load('user:id,name,email');
            }

            $handover = AdviserHandover::create([
                'campus_id' => $membership->campus_id,
                'from_user_id' => $user->id,
                'to_user_id' => $targetMembership->user_id,
                'to_membership_id' => $targetMembership->id,
                'status' => 'pending',
                'outgoing_role' => 'alumni',
                'confirmation_code' => $confirmationCode,
                'handover_notes' => isset($validated['handover_notes']) && filled($validated['handover_notes'])
                    ? trim((string) $validated['handover_notes'])
                    : null,
                'initiated_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);

            return [$handover, $targetMembership];
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

        $resultStatus = DB::transaction(function () use ($handover, $validated, $enteredCode, $user): string {
            $lockedHandover = AdviserHandover::query()
                ->whereKey($handover->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedCampus = Campus::query()
                ->whereKey($lockedHandover->campus_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedHandover->status === 'pending' && $lockedHandover->expires_at !== null && $lockedHandover->expires_at->isPast()) {
                $lockedHandover->update([
                    'status' => 'expired',
                    'responded_at' => now(),
                ]);
                $this->revokePendingIncomingAdviserMembership($lockedHandover);

                return 'expired';
            }

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

            $incomingMembership = $lockedHandover->toMembership()->lockForUpdate()->first();

            abort_unless(
                $incomingMembership !== null
                && $incomingMembership->campus_id === $lockedHandover->campus_id
                && $incomingMembership->user_id === $lockedHandover->to_user_id
                && $incomingMembership->role === CpfRole::GuidanceCounselor
                && in_array($incomingMembership->status, [MembershipStatus::Approved, MembershipStatus::Pending], true),
                422,
                'The designated incoming Guidance Counselor membership is no longer eligible for this campus handover.',
            );

            $outgoingMembership = CampusMembership::query()
                ->where('campus_id', $lockedHandover->campus_id)
                ->where('user_id', $lockedHandover->from_user_id)
                ->lockForUpdate()
                ->first();

            abort_unless(
                $outgoingMembership !== null
                && $outgoingMembership->status === MembershipStatus::Approved
                && $outgoingMembership->role === CpfRole::Adviser,
                422,
                'The initiating Club Adviser is no longer the active approved Adviser for this campus.',
            );

            $incomingMembership->update([
                'role' => CpfRole::Adviser,
                'status' => MembershipStatus::Approved,
                'approved_at' => $incomingMembership->approved_at ?? now(),
                'section' => 'Adviser',
                'course_year_section' => 'Guidance Services / Adviser',
            ]);
            $this->tenureService->openTenure($incomingMembership->fresh(), $user);

            $this->tenureService->transitionToAlumni($outgoingMembership, $user, 'adviser_handover');

            $lockedCampus->update(['adviser_name' => $incomingMembership->full_name_last_first_mi]);

            $lockedHandover->update([
                'status' => 'accepted',
                'responded_at' => now(),
            ]);

            return 'accepted';
        });

        if ($resultStatus === 'expired') {
            abort(422, 'This Adviser handover has expired and its pending invitation has been revoked.');
        }

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

    private function revokeExpiredHandoversForCampus(int $campusId): void
    {
        $expired = AdviserHandover::query()
            ->where('campus_id', $campusId)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->lockForUpdate()
            ->get();

        foreach ($expired as $item) {
            $item->update([
                'status' => 'expired',
                'responded_at' => now(),
            ]);
            $this->revokePendingIncomingAdviserMembership($item);
        }
    }

    private function revokePendingIncomingAdviserMembership(AdviserHandover $handover): void
    {
        $incomingMembership = $handover->toMembership()->lockForUpdate()->first();

        if (
            $incomingMembership !== null
            && $incomingMembership->section === 'Incoming Adviser'
            && in_array($incomingMembership->role, [CpfRole::Member, CpfRole::GuidanceCounselor], true)
            && in_array($incomingMembership->status, [MembershipStatus::Pending, MembershipStatus::Approved], true)
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
