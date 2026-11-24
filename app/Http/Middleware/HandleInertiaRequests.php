<?php

namespace App\Http\Middleware;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\FirstBootSetupController;
use App\Models\AdviserHandover;
use App\Models\Campus;
use App\Models\CampusMembership;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $membership = null;

        if ($user) {
            try {
                $membership = $user->primaryMembership()
                    ->with(['campus:id,code,name,slug', 'tenures:id,campus_membership_id,role,started_at'])
                    ->first();
            } catch (QueryException) {
                $membership = $user->primaryMembership()
                    ->with('campus:id,code,name,slug')
                    ->first();
            }
        }
        $needsAdviserSetup = FirstBootSetupController::needsAdviserSetup();

        $systemBootstrap = [
            'needsAdviserSetup' => $needsAdviserSetup,
            'activeCampus' => null,
            'campuses' => [],
        ];

        if ($needsAdviserSetup) {
            try {
                $campuses = Campus::query()
                    ->orderByDesc('is_active')
                    ->orderBy('id')
                    ->get(['id', 'code', 'name', 'current_academic_year', 'is_active']);

                $activeCampus = $campuses->firstWhere('is_active', true) ?? $campuses->first();

                $systemBootstrap['activeCampus'] = $activeCampus;
                $systemBootstrap['campuses'] = $campuses->values();
            } catch (\Throwable) {
                // Ignore if campuses table is not migrated yet
            }
        }

        $pendingAdviserHandover = [
            'incoming' => null,
            'outgoing' => null,
            'availableGuidanceCounselors' => [],
        ];

        if ($user) {
            try {
                $incoming = AdviserHandover::query()
                    ->with(['campus:id,code,name', 'fromUser:id,name'])
                    ->where('to_user_id', $user->id)
                    ->where('status', 'pending')
                    ->where('expires_at', '>', now())
                    ->latest('id')
                    ->first();

                if ($incoming) {
                    $pendingAdviserHandover['incoming'] = [
                        'id' => $incoming->id,
                        'campus_name' => $incoming->campus?->name ?? 'CPF Campus',
                        'from_name' => $incoming->fromUser?->name ?? 'Current Club Adviser',
                        'handover_notes' => $incoming->handover_notes,
                        'initiated_at' => $incoming->initiated_at?->toDayDateTimeString(),
                        'expires_at' => $incoming->expires_at?->toDayDateTimeString(),
                    ];
                }

                if ($user->isClubAdviser()) {
                    if ($membership) {
                        $pendingAdviserHandover['availableGuidanceCounselors'] = CampusMembership::query()
                            ->with('user:id,name,email')
                            ->where('campus_id', $membership->campus_id)
                            ->where('status', MembershipStatus::Approved->value)
                            ->where('role', CpfRole::GuidanceCounselor->value)
                            ->where('user_id', '!=', $user->id)
                            ->alphabetical()
                            ->get()
                            ->map(fn (CampusMembership $counselorMembership) => [
                                'membership_id' => $counselorMembership->id,
                                'user_id' => $counselorMembership->user_id,
                                'full_name' => $counselorMembership->full_name_last_first_mi,
                                'email' => $counselorMembership->user?->email ?? $counselorMembership->active_email,
                            ])
                            ->values()
                            ->all();
                    }

                    $outgoing = AdviserHandover::query()
                        ->with(['campus:id,code,name', 'toUser:id,name,email', 'toMembership:id,full_name_last_first_mi,course_year_section'])
                        ->where('from_user_id', $user->id)
                        ->where('status', 'pending')
                        ->where('expires_at', '>', now())
                        ->latest('id')
                        ->first();

                    if ($outgoing) {
                        $pendingAdviserHandover['outgoing'] = [
                            'id' => $outgoing->id,
                            'campus_name' => $outgoing->campus?->name ?? 'CPF Campus',
                            'to_name' => $outgoing->toMembership?->full_name_last_first_mi ?? $outgoing->toUser?->name ?? 'Successor',
                            'to_email' => $outgoing->toUser?->email ?? '',
                            'to_course_year_section' => $outgoing->toMembership?->course_year_section ?? '',
                            'confirmation_code' => $outgoing->confirmation_code,
                            'handover_notes' => $outgoing->handover_notes,
                            'initiated_at' => $outgoing->initiated_at?->toDayDateTimeString(),
                            'expires_at' => $outgoing->expires_at?->toDayDateTimeString(),
                        ];
                    }
                }
            } catch (\Throwable) {
                // Ignore if adviser_handovers migration has not run yet
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'systemBootstrap' => $systemBootstrap,
            'pendingAdviserHandover' => $pendingAdviserHandover,
            'auth' => [
                'user' => $user,
                'membership' => $membership,
                'role' => $user?->verifiedRole()?->value,
                'formerRole' => $user?->formerRole()?->value,
                'isAlumni' => (bool) ($user?->isAlumni() ?? false),
                'roleLabel' => $user?->verifiedRole()?->label() ?? $user?->formerRoleLabel() ?? 'Ordinary Account',
                'roleDuties' => $user?->verifiedRole()?->dutiesSummary() ?? null,
                'mustChangePassword' => (bool) ($user?->requiresPasswordChange() ?? false),
                'can' => [
                    'viewRoster' => $user ? $user->can('viewRoster', CampusMembership::class) : false,
                    'addOrApproveMembers' => $user ? $user->can('addOrApproveMember', CampusMembership::class) : false,
                    'issuePasskeys' => $user ? $user->canIssuePasskeys() : false,
                    'confirmMessengerGc' => $user ? $user->canConfirmMessengerGc() : false,
                    'validatePayments' => $user ? $user->canValidatePayments() : false,
                    'auditFinances' => $user ? $user->canAuditFinances() : false,
                    'proposeEvents' => $user ? $user->canProposeEvents() : false,
                    'isOfficerOrAdviser' => $user ? ($user->verifiedRole()?->isOfficerOrAdviser() ?? false) : false,
                    'isClubAdviser' => $user ? $user->isClubAdviser() : false,
                    'canCoordinateGuidance' => $user ? $user->canCoordinateGuidance() : false,
                    'canPublishAnnouncements' => $user ? $user->canPublishAnnouncements() : false,
                    'accessAlumniArchive' => $user ? $user->canAccessAlumniArchive() : false,
                ],
            ],
            'flash' => [
                'status' => $request->session()->get('status'),
                'passkey_issued_notice' => $request->session()->get('passkey_issued_notice'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
