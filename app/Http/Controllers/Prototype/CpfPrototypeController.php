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
        $campuses = [];
        $approvedMembersAZ = [];
        $pendingApplications = [];

        try {
            $campuses = Campus::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'slug', 'adviser_name', 'gc_group_name'])
                ->toArray();

            $activeCampusId = $campuses[0]['id'] ?? 1;

            $approvedMembersAZ = CampusMembership::query()
                ->with(['approver:id,name', 'gcConfirmer:id,name'])
                ->where('campus_id', $activeCampusId)
                ->where('status', MembershipStatus::Approved->value)
                ->alphabetical()
                ->get()
                ->toArray();

            $pendingApplications = CampusMembership::query()
                ->where('campus_id', $activeCampusId)
                ->where('status', MembershipStatus::Pending->value)
                ->alphabetical()
                ->get()
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
