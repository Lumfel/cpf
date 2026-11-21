<?php

namespace App\Http\Middleware;

use App\Models\CampusMembership;
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
        $membership = $user?->primaryMembership()->with('campus:id,code,name,slug')->first();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'membership' => $membership,
                'role' => $user?->verifiedRole()?->value,
                'roleLabel' => $user?->verifiedRole()?->label() ?? 'Ordinary Account',
                'roleDuties' => $user?->verifiedRole()?->dutiesSummary() ?? null,
                'mustChangePassword' => (bool) ($user?->requiresPasswordChange() ?? false),
                'can' => [
                    'viewRoster' => $user ? $user->can('viewRoster', CampusMembership::class) : false,
                    'addOrApproveMembers' => $user ? $user->can('addOrApproveMember', CampusMembership::class) : false,
                    'issuePasskeys' => $user ? $user->canIssuePasskeys() : false,
                    'confirmMessengerGc' => $user ? $user->canConfirmMessengerGc() : false,
                    'validatePayments' => $user ? $user->canValidatePayments() : false,
                    'auditFinances' => $user ? $user->canAuditFinances() : false,
                    'isOfficerOrAdviser' => $user ? ($user->verifiedRole()?->isOfficerOrAdviser() ?? false) : false,
                    'isClubAdviser' => $user ? $user->isClubAdviser() : false,
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
