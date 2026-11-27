<?php

namespace App\Http\Controllers\Prototype;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\CampusMembership;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CpfPrototypeController extends Controller
{
    /**
     * Render the isolated CPF Vue 3 Prototype (no login required),
     * pre-loaded with database records + fallback sample data.
     */
    public function index(Request $request): Response
    {
        abort_if(
            app()->isProduction() || ! config('cpf.prototype.enabled'),
            404,
            'Prototype workspace is disabled.',
        );

        $user = $request->user();
        abort_unless(
            $user !== null && $user->isActiveOfficerOrAdviser(),
            403,
            'Only active verified officers or the Club Adviser may access the local prototype workspace.',
        );

        $campuses = [];
        $approvedMembersAZ = [];
        $pendingApplications = [];

        try {
            $campuses = Campus::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'slug', 'adviser_name', 'gc_group_name'])
                ->toArray();

            $activeCampusId = $user->primaryMembership?->campus_id ?? ($campuses[0]['id'] ?? 1);

            $approvedMembersAZ = CampusMembership::query()
                ->with(['approver:id,name', 'gcConfirmer:id,name'])
                ->where('campus_id', $activeCampusId)
                ->where('status', MembershipStatus::Approved->value)
                ->alphabetical()
                ->get([
                    'id',
                    'campus_id',
                    'full_name_last_first_mi',
                    'course',
                    'year_level',
                    'section',
                    'course_year_section',
                    'role',
                    'status',
                    'is_in_messenger_gc',
                    'has_paid_dues',
                    'added_or_approved_by',
                    'gc_confirmed_by',
                ])
                ->toArray();

            $pendingApplications = CampusMembership::query()
                ->where('campus_id', $activeCampusId)
                ->where('status', MembershipStatus::Pending->value)
                ->where('role', CpfRole::Member->value)
                ->alphabetical()
                ->get([
                    'id',
                    'campus_id',
                    'full_name_last_first_mi',
                    'course',
                    'year_level',
                    'section',
                    'course_year_section',
                    'role',
                    'status',
                    'created_at',
                ])
                ->toArray();
        } catch (Throwable) {
            // Fallback gracefully to sample data if DB is not yet migrated in a local environment
        }

        return Inertia::render('prototype/Index', [
            'campuses' => $campuses,
            'approvedMembersAZ' => $approvedMembersAZ,
            'pendingApplications' => $pendingApplications,
            'availableRoles' => collect(CpfRole::cases())->map(fn (CpfRole $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'canManageMembers' => $r->canApproveMembers(),
                'canConfirmGc' => $r->canConfirmMessengerGc(),
                'isTopAuthority' => $r->isTopAuthority(),
            ])->values()->all(),
        ]);
    }
}
