<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Membership\ToggleMessengerGcChecklist;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CpfV4Controller;
use App\Models\CampusMembership;
use App\Models\PaymentDestination;
use App\Models\PaymentSubmission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MembershipPaymentApiController extends Controller
{
    /**
     * GET /api/v1/membership/roster
     * Officers & Club Adviser: A-Z approved same-campus roster with Messenger GC & dues flags.
     */
    public function roster(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $verifiedRole = $user->verifiedRole();

        abort_unless(
            $verifiedRole !== null && $verifiedRole->isOfficerOrAdviser(),
            403,
            'Only verified campus officers and the Club Adviser can view the campus membership roster.',
        );

        $myMembership = $user->primaryMembership()
            ->with('campus:id,code,name,gc_group_name')
            ->first();

        abort_unless(
            $myMembership !== null && $myMembership->campus !== null,
            403,
            'An active campus membership is required to view the campus roster.',
        );

        $campus = $myMembership->campus;

        $roster = CampusMembership::query()
            ->with(['gcConfirmer:id,name', 'duesConfirmer:id,name'])
            ->where('campus_id', $campus->id)
            ->where('status', MembershipStatus::Approved->value)
            ->alphabetical()
            ->get()
            ->map(function (CampusMembership $m): array {
                $roleEnum = $m->role instanceof CpfRole
                    ? $m->role
                    : CpfRole::tryFrom((string) $m->role);

                return [
                    'id' => $m->id,
                    'user_id' => $m->user_id,
                    'full_name_last_first_mi' => $m->full_name_last_first_mi,
                    'course' => $m->course,
                    'year_level' => $m->year_level,
                    'section' => $m->section,
                    'course_year_section' => $m->course_year_section,
                    'role' => $roleEnum?->value ?? (string) $m->role,
                    'role_label' => $roleEnum?->label() ?? 'Verified Peer Member',
                    'is_in_messenger_gc' => (bool) $m->is_in_messenger_gc,
                    'gc_confirmed_at' => $m->gc_confirmed_at?->toIso8601String(),
                    'gc_confirmed_by_name' => $m->gcConfirmer?->name,
                    'has_paid_dues' => (bool) $m->has_paid_dues,
                    'dues_confirmed_at' => $m->dues_confirmed_at?->toIso8601String(),
                    'dues_confirmed_by_name' => $m->duesConfirmer?->name,
                ];
            })
            ->values();

        return response()->json([
            'campus' => [
                'id' => $campus->id,
                'code' => $campus->code,
                'name' => $campus->name,
                'gc_group_name' => $campus->gc_group_name,
            ],
            'permissions' => [
                'can_confirm_messenger_gc' => $user->canConfirmMessengerGc(),
                'can_validate_payments' => $user->canValidatePayments(),
            ],
            'roster' => $roster,
        ]);
    }

    /**
     * PATCH /api/v1/membership/{membership}/toggle-gc
     * Restricted to same-campus roles with canConfirmMessengerGc().
     */
    public function toggleGc(
        Request $request,
        CampusMembership $membership,
        ToggleMessengerGcChecklist $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        Gate::forUser($actor)->authorize('confirmMessengerGc', $membership);

        abort_unless(
            $membership->status === MembershipStatus::Approved,
            422,
            'Messenger GC confirmation is only available for approved campus members.',
        );

        $updated = $action->handle($actor, $membership);

        return response()->json([
            'status' => 'updated',
            'membership_id' => $updated->id,
            'is_in_messenger_gc' => (bool) $updated->is_in_messenger_gc,
            'gc_confirmed_by' => $updated->gc_confirmed_by,
            'gc_confirmed_at' => $updated->gc_confirmed_at?->toIso8601String(),
        ]);
    }

    /**
     * PATCH /api/v1/membership/{membership}/toggle-dues
     * Restricted to same-campus roles with canValidatePayments().
     */
    public function toggleDues(
        Request $request,
        CampusMembership $membership,
        ToggleMessengerGcChecklist $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        Gate::forUser($actor)->authorize('confirmDues', $membership);

        abort_unless(
            $membership->status === MembershipStatus::Approved,
            422,
            'Membership dues confirmation is only available for approved campus members.',
        );

        $updated = $action->toggleDues($actor, $membership);

        return response()->json([
            'status' => 'updated',
            'membership_id' => $updated->id,
            'has_paid_dues' => (bool) $updated->has_paid_dues,
            'dues_confirmed_by' => $updated->dues_confirmed_by,
            'dues_confirmed_at' => $updated->dues_confirmed_at?->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/payments
     * Returns approved campus payment destinations, personal payment submissions,
     * and (for Treasurer / payment validators / financial auditors) the campus validation list.
     */
    public function paymentsIndex(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_if(
            $user->verifiedRole() === CpfRole::GuidanceCounselor,
            403,
            'Guidance Counselors are coordination-only and are blocked from student club dues.',
        );

        $membership = $user->primaryMembership()->with('campus:id,code,name')->first();
        abort_unless(
            $membership !== null && $membership->campus !== null,
            403,
            'A campus membership record is required to view payment channels and submissions.',
        );

        $campus = $membership->campus;
        $canValidatePayments = $user->canValidatePayments();
        $canAuditFinances = $user->canAuditFinances();

        $destinations = PaymentDestination::query()
            ->where('campus_id', $campus->id)
            ->where('is_active', true)
            ->whereNotNull('approved_by_adviser_id')
            ->orderBy('id')
            ->get(['id', 'channel', 'account_name', 'account_number']);

        $destinationMap = PaymentDestination::query()
            ->where('campus_id', $campus->id)
            ->get(['id', 'channel', 'account_name'])
            ->keyBy('id');

        $mySubmissions = PaymentSubmission::query()
            ->where('campus_id', $campus->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->get()
            ->map(fn (PaymentSubmission $sub): array => $this->formatPaymentSubmission(
                $sub,
                $destinationMap->get($sub->destination_id),
                $membership->full_name_last_first_mi,
                $membership->course_year_section,
            ))
            ->values();

        $validationQueue = collect();
        if ($canValidatePayments || $canAuditFinances) {
            $campusQueueRaw = PaymentSubmission::query()
                ->where('campus_id', $campus->id)
                ->latest('id')
                ->limit(100)
                ->get();

            $memberInfoByUser = CampusMembership::query()
                ->where('campus_id', $campus->id)
                ->whereIn('user_id', $campusQueueRaw->pluck('user_id')->unique())
                ->get(['user_id', 'full_name_last_first_mi', 'course_year_section'])
                ->keyBy('user_id');

            $fallbackNames = User::query()
                ->whereIn('id', $campusQueueRaw->pluck('user_id')->unique())
                ->pluck('name', 'id');

            $validationQueue = $campusQueueRaw->map(function (PaymentSubmission $sub) use (
                $destinationMap,
                $memberInfoByUser,
                $fallbackNames,
            ): array {
                $memberRow = $memberInfoByUser->get($sub->user_id);
                $memberName = $memberRow?->full_name_last_first_mi
                    ?: ($fallbackNames->get($sub->user_id) ?? 'CPF Member');

                return $this->formatPaymentSubmission(
                    $sub,
                    $destinationMap->get($sub->destination_id),
                    $memberName,
                    $memberRow?->course_year_section,
                );
            })->values();
        }

        return response()->json([
            'campus' => [
                'id' => $campus->id,
                'code' => $campus->code,
                'name' => $campus->name,
            ],
            'my_dues_status' => [
                'has_paid_dues' => (bool) $membership->has_paid_dues,
                'dues_confirmed_at' => $membership->dues_confirmed_at?->toIso8601String(),
            ],
            'permissions' => [
                'can_validate_payments' => $canValidatePayments,
                'can_audit_finances' => $canAuditFinances,
            ],
            'destinations' => $destinations,
            'my_submissions' => $mySubmissions,
            'validation_queue' => $validationQueue,
        ]);
    }

    /**
     * POST /api/v1/payments/submit
     * Member receipt upload & payment reference submission.
     */
    public function submitPayment(Request $request, CpfV4Controller $v4Controller): JsonResponse
    {
        abort_if(
            $request->user()?->isAlumni(),
            403,
            'Former member and officer accounts have read-only archive access.',
        );

        return $v4Controller->submitPayment($request);
    }

    /**
     * PATCH /api/v1/payments/{payment}/validate
     * Treasurer / Adviser validation of a submitted payment receipt.
     */
    public function validatePayment(
        Request $request,
        PaymentSubmission $payment,
        CpfV4Controller $v4Controller,
    ): JsonResponse {
        return $v4Controller->validatePayment($request, $payment);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPaymentSubmission(
        PaymentSubmission $sub,
        ?PaymentDestination $destination,
        string $memberName,
        ?string $courseYearSection,
    ): array {
        return [
            'id' => $sub->id,
            'user_id' => $sub->user_id,
            'member_name' => $memberName,
            'course_year_section' => $courseYearSection,
            'destination_id' => $sub->destination_id,
            'channel' => $destination?->channel,
            'account_name' => $destination?->account_name,
            'purpose' => $sub->purpose,
            'amount' => (float) $sub->amount,
            'reference_number' => $sub->reference_number,
            'has_receipt' => filled($sub->receipt_hdd_path),
            'status' => $sub->status,
            'treasurer_notes' => $sub->treasurer_notes,
            'validated_by_treasurer_id' => $sub->validated_by_treasurer_id,
            'validated_at' => $sub->validated_at ? (string) $sub->validated_at : null,
            'created_at' => $sub->created_at?->toIso8601String(),
        ];
    }
}
