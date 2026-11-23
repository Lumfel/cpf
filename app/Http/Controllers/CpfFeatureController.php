<?php

namespace App\Http\Controllers;

use App\Actions\Gc\ArchiveAndResetOfficerGc;
use App\Actions\Security\IssueMemberPasskey;
use App\Actions\Support\RequestTestingRoom;
use App\Actions\Support\SubmitGuidanceReferral;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Events\OfficerGcMessageSent;
use App\Models\CampusCounselor;
use App\Models\CampusMembership;
use App\Models\CounselorGcMessage;
use App\Models\GuidanceReferral;
use App\Models\OfficerGcChannel;
use App\Models\OfficerGcMessage;
use App\Models\ReferralAuditLog;
use App\Models\RoomRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CpfFeatureController extends Controller
{
    public function issuePasskey(Request $request, CampusMembership $membership, IssueMemberPasskey $action): JsonResponse
    {
        Gate::authorize('issuePasskey', $membership);
        $forceRegenerate = (bool) $request->boolean('regenerate', false);
        $result = $action->handle(
            $request->user(),
            $membership,
            (bool) $request->boolean('require_2fa', true),
            $forceRegenerate,
        );

        return response()->json([
            'status' => 'issued',
            'issuance_id' => $result['issuance']->id,
            'enrollment_code' => $result['enrollment_code'],
            'is_locked' => $result['is_locked'],
            'regenerated' => $result['regenerated'],
            'require_2fa' => $result['issuance']->require_2fa,
            'expires_at' => $result['issuance']->expires_at,
        ]);
    }

    public function regenerateOwnPasskey(Request $request, IssueMemberPasskey $action): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;
        abort_unless(
            $user && $membership && $membership->status === MembershipStatus::Approved,
            403,
            'An approved campus membership is required to manage a passkey code.',
        );

        $forceRegenerate = (bool) $request->boolean('regenerate', true);
        $result = $action->handle($user, $membership, true, $forceRegenerate);

        return response()->json([
            'status' => 'issued',
            'issuance_id' => $result['issuance']->id,
            'enrollment_code' => $result['enrollment_code'],
            'is_locked' => $result['is_locked'],
            'regenerated' => $result['regenerated'],
            'expires_at' => $result['issuance']->expires_at?->toDayDateTimeString(),
        ]);
    }

    public function requestTestingRoom(Request $request, RequestTestingRoom $action): JsonResponse
    {
        $membership = $request->user()?->primaryMembership;
        $campusId = $membership?->campus_id ?? (int) $request->input('campus_id');

        $validated = $request->validate([
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'counselor_id' => ['nullable', 'integer', 'exists:campus_counselors,id'],
            'purpose' => ['required', 'string', 'max:200'],
            'request_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        abort_unless($campusId > 0, 422, 'A valid campus is required for Testing Room requests.');

        if (! empty($validated['counselor_id'])) {
            abort_unless(
                CampusCounselor::query()
                    ->where('id', (int) $validated['counselor_id'])
                    ->where('campus_id', $campusId)
                    ->exists(),
                422,
                'Selected counselor does not belong to this campus.',
            );
        }

        $roomRequest = $action->handle($request->user(), $campusId, $validated);

        return response()->json(['status' => 'pending', 'room_request' => $roomRequest], 201);
    }

    public function reviewTestingRoom(Request $request, RoomRequest $roomRequest): JsonResponse
    {
        $user = $request->user();
        $role = $user?->verifiedRole();
        $membership = $user?->primaryMembership;

        abort_unless(
            $role && $role->isOfficerOrAdviser() && $membership && $membership->campus_id === $roomRequest->campus_id,
            403,
            'Only verified campus officers or the Club Adviser can review Testing Room requests.',
        );

        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_notes' => ['nullable', 'string', 'max:255'],
        ]);

        $roomRequest->update([
            'status' => $validated['status'],
            'reviewed_by_user_id' => $user->id,
            'review_notes' => $validated['review_notes'] ?? null,
        ]);

        return response()->json([
            'status' => $roomRequest->status,
            'room_request' => $roomRequest->fresh(),
        ]);
    }

    public function submitReferral(Request $request, SubmitGuidanceReferral $action): JsonResponse
    {
        $membership = $request->user()?->primaryMembership;

        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'referral_type' => ['required', Rule::in(['self_counseling', 'peer_referral'])],
            'student_identifier' => ['required', 'string', 'max:180'],
            'student_course_section' => ['nullable', 'string', 'max:80'],
            'suggested_priority' => ['required', Rule::enum(ReferralPriority::class)],
            'concern_summary' => ['required', 'string', 'max:4000'],
        ]);

        if ($membership !== null) {
            abort_unless(
                (int) $validated['campus_id'] === $membership->campus_id,
                403,
                'Referrals must be submitted to your assigned campus chapter.',
            );
        }

        $referral = $action->handle($request->user(), (int) $validated['campus_id'], $validated, $request->ip());

        return response()->json(['status' => 'submitted', 'referral_id' => $referral->id], 201);
    }

    public function confirmReferral(Request $request, GuidanceReferral $referral): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $referral->campus_id,
            403,
            'Only the Club Adviser / Guidance Counselor of this campus can confirm referral priority.',
        );

        $validated = $request->validate([
            'counselor_confirmed_priority' => ['required', Rule::enum(ReferralPriority::class)],
            'status' => ['required', 'in:in_review,scheduled,resolved'],
        ]);

        $confirmedPriority = ReferralPriority::from($validated['counselor_confirmed_priority']);

        DB::transaction(function () use ($referral, $confirmedPriority, $validated, $user, $request): void {
            $referral->update([
                'counselor_confirmed_priority' => $confirmedPriority,
                'triage_response_target' => $confirmedPriority->responseTarget(),
                'assigned_counselor_id' => $user->id,
                'status' => $validated['status'],
            ]);

            ReferralAuditLog::create([
                'referral_id' => $referral->id,
                'actor_user_id' => $user->id,
                'action' => 'counselor_confirmed',
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'status' => $referral->status,
            'referral' => $referral->fresh(),
        ]);
    }

    public function sendOfficerGcMessage(Request $request, OfficerGcChannel $channel): JsonResponse
    {
        $user = $request->user();
        $role = $user?->verifiedRole();
        $membership = $user?->primaryMembership;

        abort_unless(
            $role && $role->isOfficerOrAdviser() && $membership && $membership->campus_id === $channel->campus_id && $channel->is_active,
            403,
            'Only active campus Officers and the Club Adviser may message this Officers Private GC.',
        );

        $validated = $request->validate(['message_body' => ['required', 'string', 'max:2000']]);
        $msg = OfficerGcMessage::create([
            'channel_id' => $channel->id,
            'sender_user_id' => $user->id,
            'sender_role' => $role->value,
            'message_body' => trim($validated['message_body']),
        ]);

        $msg->load('sender:id,name');
        OfficerGcMessageSent::dispatch($channel, $msg);

        return response()->json(['status' => 'sent', 'message' => $msg], 201);
    }

    public function archiveOfficerGc(Request $request, OfficerGcChannel $channel, ArchiveAndResetOfficerGc $action): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $channel->campus_id,
            403,
            'Only the campus Club Adviser may archive and reset the Officers Private GC.',
        );

        $archiveDir = (string) config('cpf.archive_path', storage_path('app/archives'));
        $archive = $action->handle($user, $channel, $archiveDir);

        return response()->json(['status' => 'archived', 'archive' => $archive]);
    }

    public function sendCounselorGcMessage(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user?->verifiedRole();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership !== null,
            403,
            'Counselors Private GC is restricted to the campus Club Adviser and Guidance Counselors.',
        );

        $validated = $request->validate(['message_body' => ['required', 'string', 'max:2000']]);

        $msg = CounselorGcMessage::create([
            'campus_id' => $membership->campus_id,
            'sender_user_id' => $user->id,
            'sender_role' => $role?->value ?? 'adviser',
            'message_body' => trim($validated['message_body']),
        ]);

        $msg->load('sender:id,name');

        return response()->json([
            'status' => 'sent',
            'message' => [
                'id' => $msg->id,
                'sender_user_id' => $msg->sender_user_id,
                'sender_name' => $msg->sender?->name ?? $user->name,
                'sender' => [
                    'id' => $user->id,
                    'name' => $msg->sender?->name ?? $user->name,
                ],
                'sender_role' => $msg->sender_role,
                'message_body' => $msg->message_body,
                'created_at' => (string) $msg->created_at,
            ],
        ], 201);
    }
}
