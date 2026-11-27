<?php

namespace App\Http\Controllers\Membership;

use App\Actions\Membership\AddOrApproveCampusMember;
use App\Actions\Membership\SubmitOnlineMembershipApplication;
use App\Actions\Membership\ToggleMessengerGcChecklist;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\FirstBootSetupController;
use App\Http\Requests\Membership\OfficerAddMemberRequest;
use App\Http\Requests\Membership\SubmitMembershipApplicationRequest;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\DuesAuditLog;
use App\Models\MemberInfoChangeRequest;
use App\Models\User;
use App\Services\ClubTenureService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CampusMembershipController extends Controller
{
    private function resolveActor(Request $request): ?User
    {
        return $request->user();
    }

    public function showQr(Request $request, CampusMembership $membership): JsonResponse
    {
        $actor = $this->resolveActor($request);
        abort_if(! $actor, 401);

        abort_unless(
            $actor->can('viewRoster', CampusMembership::class)
            && $actor->primaryMembership?->campus_id === $membership->campus_id,
            403,
            'Only verified campus officers or the Club Adviser can view member attendance QR cards.',
        );

        abort_unless(
            $membership->status === MembershipStatus::Approved,
            422,
            'Attendance QR codes are only available for approved campus members.',
        );

        $membership->loadMissing('campus:id,code');
        $campusCode = $membership->campus?->code ?? 'CHMSU';
        $roleValue = $membership->role instanceof CpfRole ? $membership->role->value : (string) $membership->role;

        $rawPayload = "CPF|campus:{$campusCode}|user:{$membership->user_id}|role:{$roleValue}";
        $signature = substr(hash_hmac('sha256', $rawPayload, (string) config('app.key')), 0, 16);
        $qrPayload = "{$rawPayload}|sig:{$signature}";

        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));
        $qrSvg = $writer->writeString($qrPayload);

        return response()->json([
            'full_name' => $membership->full_name_last_first_mi,
            'course' => $membership->course,
            'year_level' => $membership->year_level,
            'section' => $membership->section,
            'course_year_section' => $membership->course_year_section,
            'qr_svg' => $qrSvg,
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $this->resolveActor($request);
        $myMembership = $user?->primaryMembership()->with(['campus', 'approver', 'gcConfirmer', 'duesConfirmer'])->first();

        $campuses = Campus::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'slug', 'current_academic_year', 'adviser_name', 'gc_group_name']);

        $permissions = [
            'canViewRoster' => $user ? $user->can('viewRoster', CampusMembership::class) : false,
            'canApproveMembers' => $user ? $user->can('addOrApproveMember', CampusMembership::class) : false,
            'canIssuePasskeys' => $user ? $user->can('issuePasskey', CampusMembership::class) : false,
            'canConfirmMessengerGc' => $user ? $user->canConfirmMessengerGc() : false,
            'canConfirmDues' => $user ? $user->canValidatePayments() : false,
            'isClubAdviser' => $user ? $user->isClubAdviser() : false,
            'verifiedRole' => $user?->verifiedRole()?->value,
            'verifiedRoleLabel' => $user?->verifiedRole()?->label() ?? 'Ordinary Account (Not Yet Verified)',
        ];

        $pendingApplications = [];
        $approvedMembersAZ = [];
        $alumniCount = 0;
        $activeCampus = $myMembership?->campus ?? $campuses->first();

        if ($activeCampus) {
            $alumniCount = CampusMembership::query()
                ->where('campus_id', $activeCampus->id)
                ->where('status', MembershipStatus::Alumni->value)
                ->count();
        }

        if ($permissions['canViewRoster'] && $activeCampus) {
            $pendingApplications = CampusMembership::query()
                ->with(['user:id,name,email'])
                ->where('campus_id', $activeCampus->id)
                ->where('status', MembershipStatus::Pending->value)
                ->where('role', CpfRole::Member->value)
                ->alphabetical()
                ->get();

            $approvedMembersAZ = CampusMembership::query()
                ->with(['user:id,name,email', 'approver:id,name', 'gcConfirmer:id,name', 'duesConfirmer:id,name'])
                ->where('campus_id', $activeCampus->id)
                ->where('status', MembershipStatus::Approved->value)
                ->alphabetical()
                ->get();
        }

        $myChangeRequests = $myMembership
            ? MemberInfoChangeRequest::query()
                ->with(['reviewer:id,name'])
                ->where('campus_membership_id', $myMembership->id)
                ->latest()
                ->limit(10)
                ->get()
            : [];

        $pendingChangeRequests = ($permissions['isClubAdviser'] && $activeCampus)
            ? MemberInfoChangeRequest::query()
                ->with(['membership:id,full_name_last_first_mi,course_year_section,role,active_email'])
                ->where('campus_id', $activeCampus->id)
                ->where('status', 'pending')
                ->oldest()
                ->get()
            : [];

        return Inertia::render('membership/Index', [
            'campuses' => $campuses,
            'activeCampus' => $activeCampus,
            'myMembership' => $myMembership,
            'permissions' => $permissions,
            'pendingApplications' => $pendingApplications,
            'approvedMembersAZ' => $approvedMembersAZ,
            'alumniCount' => $alumniCount,
            'myChangeRequests' => $myChangeRequests,
            'pendingChangeRequests' => $pendingChangeRequests,
            'availableRoles' => collect(CpfRole::cases())
                ->filter(fn (CpfRole $r) => ! in_array($r, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true))
                ->map(fn (CpfRole $r) => [
                    'value' => $r->value,
                    'label' => $r->label(),
                    'canApproveMembers' => $r->canApproveMembers(),
                    'canIssuePasskeys' => $r->canIssuePasskeys(),
                ])
                ->values(),
        ]);
    }

    public function storeApplication(SubmitMembershipApplicationRequest $request, SubmitOnlineMembershipApplication $action): RedirectResponse
    {
        abort_if(
            FirstBootSetupController::needsAdviserSetup(),
            503,
            'Campus portal is awaiting Club Adviser initialization.',
        );

        $validated = $request->validated();
        $actor = $request->user();

        DB::transaction(function () use ($actor, $validated, $action): void {
            if ($actor) {
                User::query()->whereKey($actor->id)->lockForUpdate()->first();
                $existingMemberships = CampusMembership::query()
                    ->where('user_id', $actor->id)
                    ->lockForUpdate()
                    ->get();

                if ($existingMemberships->contains(
                    fn (CampusMembership $m): bool => $m->status === MembershipStatus::Alumni
                        || in_array($m->role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true),
                )) {
                    throw ValidationException::withMessages([
                        'active_email' => 'Former CPF members, Guidance Counselors, and Club Advisers cannot submit a student membership application overwrite.',
                    ]);
                }

                if ($existingMemberships->contains(fn (CampusMembership $m): bool => $m->status === MembershipStatus::Approved)) {
                    throw ValidationException::withMessages([
                        'active_email' => 'You are already an official CPF member. Please submit an Information Change Request for Club Adviser approval instead.',
                    ]);
                }

                if ($existingMemberships->contains(fn (CampusMembership $m): bool => $m->status === MembershipStatus::Pending)) {
                    throw ValidationException::withMessages([
                        'active_email' => 'Your CPF Membership Application is already pending review by your campus officers.',
                    ]);
                }
            } else {
                $email = strtolower(trim((string) $validated['active_email']));

                if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages([
                        'active_email' => 'An account with this email already exists. Please sign in.',
                    ]);
                }

                $actor = new User([
                    'name' => CampusMembership::formatLastFirstMi(
                        (string) $validated['first_name'],
                        $validated['middle_name'] ?? null,
                        (string) $validated['last_name'],
                    ),
                    'email' => $email,
                    'password' => Hash::make(Str::password(16)),
                    'must_change_password' => true,
                ]);
                $actor->save();
            }

            $action->handle($actor, $validated);
        });

        return back(fallback: route('membership.index'))->with('status', 'Your CPF Membership Application has been submitted.');
    }

    public function storeOfficerMember(OfficerAddMemberRequest $request, AddOrApproveCampusMember $action): RedirectResponse
    {
        $actor = $this->resolveActor($request);
        abort_if(! $actor, 401);
        $result = $action->addDirectly($actor, $request->validated());
        $member = $result['membership'];

        $emailSuffix = $this->formatEmailStatusSuffix($result['email_status'], (string) $member->active_email);

        $redirect = to_route('membership.index')->with('status', "Added and verified {$member->full_name_last_first_mi} with a locked 12-character passkey code.{$emailSuffix}");

        if ($result['passkey_code']) {
            $redirect->with('passkey_issued_notice', [
                'member_name' => $member->full_name_last_first_mi,
                'email' => $member->active_email,
                'passkey_code' => $result['passkey_code'],
                'emailed' => $result['emailed'],
                'email_status' => $result['email_status'],
                'email_error' => $result['email_error'],
            ]);
        }

        return $redirect;
    }

    public function approve(Request $request, CampusMembership $membership, AddOrApproveCampusMember $action): RedirectResponse
    {
        $actor = $this->resolveActor($request);
        abort_if(! $actor, 401);

        Gate::forUser($actor)->authorize('addOrApproveMember', CampusMembership::class);
        abort_unless(
            $actor->primaryMembership?->campus_id === $membership->campus_id,
            403,
            'You can only approve membership applications for your own campus.',
        );

        abort_unless(
            $membership->status === MembershipStatus::Pending,
            422,
            'Only pending membership applications can be approved.',
        );

        Gate::forUser($actor)->authorize('addOrApproveMember', $membership);

        $result = $action->approveApplication($actor, $membership, $request->input('remarks'));
        $approved = $result['membership'];

        $emailSuffix = $this->formatEmailStatusSuffix($result['email_status'], (string) $approved->active_email);

        return to_route('membership.index')
            ->with('status', "Approved {$approved->full_name_last_first_mi} and issued their locked 12-character passkey code.{$emailSuffix}")
            ->with('passkey_issued_notice', [
                'member_name' => $approved->full_name_last_first_mi,
                'email' => $approved->active_email,
                'passkey_code' => $result['passkey_code'],
                'emailed' => $result['emailed'],
                'email_status' => $result['email_status'],
                'email_error' => $result['email_error'],
            ]);
    }

    private function formatEmailStatusSuffix(string $emailStatus, string $email): string
    {
        return match ($emailStatus) {
            'sent' => " One-time passkey email delivered to {$email}.",
            'already_sent' => ' One-time passkey email was already delivered previously.',
            'logged' => ' Mailer is in log mode — email written to storage/logs/laravel.log.',
            default => ' Email could not be delivered — check the notice below and share the code manually.',
        };
    }

    public function toggleGc(Request $request, CampusMembership $membership, ToggleMessengerGcChecklist $action): RedirectResponse
    {
        $actor = $this->resolveActor($request);
        abort_if(! $actor, 401);
        Gate::forUser($actor)->authorize('confirmMessengerGc', $membership);
        $action->handle($actor, $membership);

        return to_route('membership.index');
    }

    public function toggleDues(Request $request, CampusMembership $membership, ToggleMessengerGcChecklist $action): RedirectResponse
    {
        $actor = $this->resolveActor($request);
        abort_if(! $actor, 401);
        Gate::forUser($actor)->authorize('confirmDues', $membership);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'payment_submission_id' => ['nullable', 'integer', 'exists:payment_submissions,id'],
        ]);

        $action->toggleDues(
            $actor,
            $membership,
            isset($validated['reason']) ? trim((string) $validated['reason']) : null,
            isset($validated['payment_submission_id']) ? (int) $validated['payment_submission_id'] : null,
        );

        return back(fallback: route('membership.index'));
    }

    /**
     * Adviser-only Conflict Freeze failsafe: suspend or restore an officer's write privileges
     * and immediately revoke their Sanctum mobile tokens on suspension.
     */
    public function toggleOfficerSuspension(Request $request, CampusMembership $membership): JsonResponse|RedirectResponse
    {
        $actor = $this->resolveActor($request);
        abort_if(! $actor, 401);

        abort_unless(
            $actor->isClubAdviser() && $actor->primaryMembership?->campus_id === $membership->campus_id,
            403,
            'Only the campus Club Adviser may suspend or restore officer privileges.',
        );

        abort_if(
            in_array($membership->role, [CpfRole::Adviser, CpfRole::GuidanceCounselor, CpfRole::Member], true),
            422,
            'Privilege suspension applies only to elected student officers.',
        );

        $validated = $request->validate([
            'suspend' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $suspend = (bool) $validated['suspend'];

        $membership->update([
            'privileges_suspended_at' => $suspend ? now() : null,
            'suspension_reason' => $suspend ? trim((string) ($validated['reason'] ?? 'Suspended by Club Adviser for governance review.')) : null,
            'suspended_by' => $suspend ? $actor->id : null,
        ]);

        if ($suspend) {
            $membership->user?->tokens()->delete();
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $membership->user_id)
                ->delete();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => $suspend ? 'suspended' : 'restored',
                'privileges_suspended_at' => $membership->fresh()?->privileges_suspended_at?->toIso8601String(),
                'suspension_reason' => $membership->fresh()?->suspension_reason,
            ]);
        }

        return back(fallback: route('membership.index'));
    }

    /**
     * Read-only immutable financial dues audit trail for Auditor & Club Adviser.
     */
    public function duesAuditLogs(Request $request): JsonResponse
    {
        $actor = $this->resolveActor($request);
        abort_if(! $actor, 401);
        $membership = $actor->primaryMembership;

        abort_unless(
            $actor->canAuditFinances() && $membership !== null,
            403,
            'Dues audit logs are restricted to the Club Adviser and Auditor.',
        );

        $logs = DuesAuditLog::query()
            ->with(['actor:id,name', 'target:id,name'])
            ->where('campus_id', $membership->campus_id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (DuesAuditLog $log) => [
                'id' => $log->id,
                'actor_name' => $log->actor?->name ?? 'Officer',
                'target_name' => $log->target?->name ?? 'Member',
                'old_status' => $log->old_status,
                'new_status' => $log->new_status,
                'payment_submission_id' => $log->payment_submission_id,
                'reason' => $log->reason,
                'created_at' => $log->created_at?->toDateTimeString(),
            ])
            ->values();

        return response()->json(['logs' => $logs]);
    }

    public function updateRole(Request $request, CampusMembership $membership): RedirectResponse
    {
        $actor = $this->resolveActor($request);
        abort_if(! $actor, 401);

        abort_unless(
            $actor->isClubAdviser() && $actor->primaryMembership?->campus_id === $membership->campus_id,
            403,
            'Officer roles are assigned through General Assembly Elections, Adviser Handover, or Counselor Invitations.',
        );

        abort(
            422,
            'Officer roles are assigned through General Assembly Elections, Adviser Handover, or Counselor Invitations.',
        );
    }

    public function transitionToAlumni(
        Request $request,
        CampusMembership $membership,
        ClubTenureService $tenureService,
    ): JsonResponse|RedirectResponse {
        $actor = $this->resolveActor($request);
        abort_if(! $actor, 401);

        abort_unless(
            $actor->isClubAdviser() && $actor->primaryMembership?->campus_id === $membership->campus_id,
            403,
            'Only the campus Club Adviser can transition members or officers to Former status.',
        );

        abort_unless(
            $membership->status === MembershipStatus::Approved,
            422,
            'Only currently verified active members or officers can be marked as Former.',
        );

        abort_if(
            $membership->role === CpfRole::Adviser,
            422,
            'Use the Adviser Role Handover tab to safely transfer Club Adviser authority before stepping down.',
        );

        $validated = $request->validate([
            'reason' => ['nullable', Rule::in(['graduated', 'term_completed', 'stepped_down', 'transferred'])],
            'ended_at' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        if (array_key_exists('remarks', $validated) && filled($validated['remarks'])) {
            $membership->remarks = trim((string) $validated['remarks']);
            $membership->save();
        }

        $reason = (string) ($validated['reason'] ?? 'graduated');
        $endedAt = ! empty($validated['ended_at'])
            ? Carbon::parse((string) $validated['ended_at'])->endOfDay()
            : null;

        $updated = $tenureService->transitionToAlumni($membership, $actor, $reason, $endedAt);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'alumni',
                'membership' => $updated,
            ]);
        }

        return to_route('membership.index')->with(
            'status',
            "Transitioned {$updated->full_name_last_first_mi} ({$updated->formerRoleLabel()}) to the Former Members & Legacy Archive with read-only access.",
        );
    }

    public function storeChangeRequest(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $actor, 401);

        $membership = $actor->primaryMembership;
        abort_if(
            ! $membership || $membership->status !== MembershipStatus::Approved,
            403,
            'Only official approved CPF members can request information changes.',
        );

        $hasPending = MemberInfoChangeRequest::query()
            ->where('campus_membership_id', $membership->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            throw ValidationException::withMessages([
                'reason' => 'You already have a pending information change request awaiting Club Adviser review.',
            ]);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:100'],
            'course' => ['required', 'string', 'max:100'],
            'year_level' => ['required', 'string', 'max:30'],
            'section' => ['required', 'string', 'max:30'],
            'sex' => ['required', Rule::in(['Male', 'Female'])],
            'gender_identity' => ['nullable', Rule::in(['Man', 'Woman', 'Non-binary', 'Prefer not to say', 'Others'])],
            'gender_identity_other' => ['nullable', 'string', 'max:100'],
            'birthdate' => ['required', 'date'],
            'active_email' => ['required', 'email', 'max:255'],
            'facebook_profile_url' => [
                'required',
                'url',
                'max:500',
                'regex:/^https?:\/\/(www\.|m\.|web\.)?(facebook\.com|fb\.com)\/.+/i',
            ],
            'active_fb_account' => ['nullable', 'string', 'max:255'],
            'cellphone_number' => ['required', 'string', 'max:30'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'facebook_profile_url.regex' => 'Please enter a valid Facebook profile link (e.g. https://www.facebook.com/username).',
        ]);

        $editableFields = [
            'first_name',
            'middle_name',
            'last_name',
            'course',
            'year_level',
            'section',
            'sex',
            'gender_identity',
            'gender_identity_other',
            'birthdate',
            'active_email',
            'facebook_profile_url',
            'active_fb_account',
            'cellphone_number',
        ];

        $diff = [];
        foreach ($editableFields as $field) {
            $oldRaw = $field === 'birthdate'
                ? $membership->birthdate?->format('Y-m-d')
                : $membership->getAttribute($field);

            $oldVal = trim((string) ($oldRaw ?? ''));
            $newVal = trim((string) ($validated[$field] ?? ''));

            if ($field === 'gender_identity_other' && ($validated['gender_identity'] ?? null) !== 'Others') {
                $newVal = '';
            }

            if ($oldVal !== $newVal) {
                $diff[$field] = [
                    'old' => $oldVal !== '' ? $oldVal : null,
                    'new' => $newVal !== '' ? $newVal : null,
                ];
            }
        }

        if ($diff === []) {
            throw ValidationException::withMessages([
                'reason' => 'Please modify at least one field before submitting an information change request.',
            ]);
        }

        MemberInfoChangeRequest::create([
            'campus_membership_id' => $membership->id,
            'campus_id' => $membership->campus_id,
            'user_id' => $actor->id,
            'requested_changes' => $diff,
            'reason' => trim($validated['reason']),
            'status' => 'pending',
        ]);

        return to_route('membership.index')
            ->with('status', 'Your information change request has been submitted for Club Adviser approval.');
    }

    public function reviewChangeRequest(Request $request, MemberInfoChangeRequest $changeRequest): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $actor, 401);

        $actorMembership = $actor->primaryMembership;
        abort_if(
            ! $actor->isClubAdviser() || ! $actorMembership || $actorMembership->campus_id !== $changeRequest->campus_id,
            403,
            'Only your campus Club Adviser can approve or decline member information change requests.',
        );

        abort_if(
            $changeRequest->status !== 'pending',
            422,
            'This information change request has already been reviewed.',
        );

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'declined'])],
            'adviser_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $allowedFields = [
            'first_name',
            'middle_name',
            'last_name',
            'course',
            'year_level',
            'section',
            'sex',
            'gender_identity',
            'gender_identity_other',
            'birthdate',
            'active_email',
            'facebook_profile_url',
            'active_fb_account',
            'cellphone_number',
        ];

        $membership = DB::transaction(function () use ($validated, $changeRequest, $actor, $allowedFields): CampusMembership {
            $lockedRequest = MemberInfoChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if(
                $lockedRequest->status !== 'pending',
                422,
                'This information change request has already been reviewed.',
            );

            $membership = $lockedRequest->membership()->with('user')->lockForUpdate()->firstOrFail();

            if ($validated['decision'] === 'approved') {
                $updates = [];
                foreach ($lockedRequest->requested_changes as $field => $change) {
                    if (in_array($field, $allowedFields, true)) {
                        $updates[$field] = $change['new'] ?? null;
                    }
                }

                if (isset($updates['active_email']) && filled($updates['active_email'])) {
                    $normalizedEmail = strtolower(trim((string) $updates['active_email']));
                    $updates['active_email'] = $normalizedEmail;

                    Validator::make(
                        ['active_email' => $normalizedEmail],
                        [
                            'active_email' => [
                                'required',
                                'email',
                                'max:255',
                                Rule::unique('users', 'email')->ignore($membership->user_id),
                            ],
                        ],
                    )->validate();
                }

                $membership->fill($updates);

                $membership->full_name_last_first_mi = CampusMembership::formatLastFirstMi(
                    (string) $membership->first_name,
                    $membership->middle_name,
                    (string) $membership->last_name,
                );
                $membership->course_year_section = CampusMembership::formatCourseYearSection(
                    (string) $membership->course,
                    (string) $membership->year_level,
                    (string) $membership->section,
                );
                $membership->save();

                if ($membership->user) {
                    $userUpdates = [];

                    if (isset($updates['first_name']) || isset($updates['middle_name']) || isset($updates['last_name'])) {
                        $userUpdates['name'] = $membership->full_name_last_first_mi;
                    }

                    if (isset($updates['active_email']) && filled($updates['active_email'])) {
                        $userUpdates['email'] = $updates['active_email'];
                    }

                    if ($userUpdates !== []) {
                        $membership->user->forceFill($userUpdates)->save();
                    }
                }
            }

            $lockedRequest->forceFill([
                'status' => $validated['decision'],
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'adviser_notes' => trim((string) ($validated['adviser_notes'] ?? '')) ?: null,
            ])->save();

            return $membership;
        });

        $verb = $validated['decision'] === 'approved' ? 'Approved and applied' : 'Declined';

        return to_route('membership.index')
            ->with('status', "{$verb} information change request for {$membership->full_name_last_first_mi}.");
    }
}
