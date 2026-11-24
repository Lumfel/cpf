<?php

namespace App\Http\Controllers;

use App\Actions\Gc\ArchiveAndResetOfficerGc;
use App\Actions\Gc\ArchiveCounselorGc;
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
        abort_if(
            $request->user()?->isAlumni(),
            403,
            'Former member and officer accounts have read-only archive access.',
        );

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
            $role && ($role->isOfficerOrAdviser() || $role->canCoordinateGuidance()) && $membership && $membership->campus_id === $roomRequest->campus_id,
            403,
            'Only verified campus officers, Guidance Counselors, or the Club Adviser can review Testing Room requests.',
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
        abort_if(
            $request->user()?->isAlumni(),
            403,
            'Former member and officer accounts have read-only archive access.',
        );

        $membership = $request->user()?->primaryMembership;

        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'referral_type' => ['required', Rule::in(['self_counseling', 'peer_referral'])],
            'student_identifier' => ['required', 'string', 'max:180'],
            'student_course_section' => ['nullable', 'string', 'max:80'],
            'suggested_priority' => ['required', Rule::enum(ReferralPriority::class)],
            'concern_summary' => ['required', 'string', 'max:4000'],
            'counselor_id' => ['nullable', 'integer', 'exists:campus_counselors,id'],
            'preferred_date' => ['nullable', 'required_with:preferred_start_time,preferred_end_time', 'date', 'after_or_equal:today'],
            'preferred_start_time' => ['nullable', 'required_with:preferred_date,preferred_end_time', 'date_format:H:i'],
            'preferred_end_time' => ['nullable', 'required_with:preferred_date,preferred_start_time', 'date_format:H:i', 'after:preferred_start_time'],
        ]);

        $campusId = (int) $validated['campus_id'];

        if ($membership !== null) {
            abort_unless(
                $campusId === $membership->campus_id,
                403,
                'Referrals must be submitted to your assigned campus chapter.',
            );
        }

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

        $referral = $action->handle($request->user(), $campusId, $validated, $request->ip());

        return response()->json([
            'status' => 'submitted',
            'feedback_status' => $referral->feedback_status,
            'referral_id' => $referral->id,
        ], 201);
    }

    public function confirmReferral(Request $request, GuidanceReferral $referral): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->canCoordinateGuidance() && $membership?->campus_id === $referral->campus_id,
            403,
            'Only the Club Adviser or a campus Guidance Counselor can update referral status and triage.',
        );

        $validated = $request->validate([
            'counselor_confirmed_priority' => ['nullable', Rule::enum(ReferralPriority::class)],
            'feedback_status' => ['nullable', 'required_without:status', Rule::in(['pending', 'ongoing', 'completed'])],
            'status' => ['nullable', 'required_without:feedback_status', Rule::in(['pending', 'ongoing', 'completed', 'submitted', 'in_review', 'scheduled', 'resolved'])],
            'safe_scheduling_note' => ['nullable', 'string', 'max:500'],
            'confidential_clinical_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $confirmedPriority = isset($validated['counselor_confirmed_priority'])
            ? ReferralPriority::from($validated['counselor_confirmed_priority'])
            : ($referral->counselor_confirmed_priority ?? $referral->suggested_priority);

        $rawFeedback = $validated['feedback_status'] ?? null;
        $rawStatus = $validated['status'] ?? null;

        $feedbackStatus = $rawFeedback ?? match ($rawStatus) {
            'pending', 'submitted' => 'pending',
            'completed', 'resolved' => 'completed',
            default => 'ongoing',
        };

        $internalStatus = match ($rawStatus) {
            'in_review', 'scheduled', 'resolved', 'submitted' => $rawStatus,
            default => match ($feedbackStatus) {
                'pending' => 'submitted',
                'completed' => 'resolved',
                default => 'in_review',
            },
        };

        DB::transaction(function () use ($referral, $confirmedPriority, $feedbackStatus, $internalStatus, $validated, $user, $request): void {
            $payload = [
                'counselor_confirmed_priority' => $confirmedPriority,
                'triage_response_target' => $confirmedPriority->responseTarget(),
                'assigned_counselor_id' => $user->id,
                'status' => $internalStatus,
                'feedback_status' => $feedbackStatus,
            ];

            if (array_key_exists('safe_scheduling_note', $validated)) {
                $payload['safe_scheduling_note'] = filled($validated['safe_scheduling_note'])
                    ? trim((string) $validated['safe_scheduling_note'])
                    : null;
            }

            if (array_key_exists('confidential_clinical_notes', $validated)) {
                $payload['confidential_clinical_notes'] = filled($validated['confidential_clinical_notes'])
                    ? trim((string) $validated['confidential_clinical_notes'])
                    : null;
            }

            if ($referral->room_request_id !== null && $feedbackStatus === 'ongoing') {
                RoomRequest::query()
                    ->whereKey($referral->room_request_id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'approved',
                        'reviewed_by_user_id' => $user->id,
                    ]);
            }

            $referral->update($payload);

            ReferralAuditLog::create([
                'referral_id' => $referral->id,
                'actor_user_id' => $user->id,
                'action' => 'counselor_confirmed',
                'ip_address' => $request->ip(),
            ]);
        });

        $fresh = $referral->fresh();

        return response()->json([
            'status' => $fresh->status,
            'feedback_status' => $fresh->feedback_status,
            'referral' => $fresh,
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

        $nextChannel = OfficerGcChannel::query()
            ->where('campus_id', $channel->campus_id)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        return response()->json([
            'status' => 'archived',
            'archive' => $archive,
            'next_channel' => $nextChannel,
        ]);
    }

    public function archiveCounselorGc(Request $request, ArchiveCounselorGc $action): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->canCoordinateGuidance() && $membership !== null,
            403,
            'Only the campus Club Adviser and Guidance Counselors may archive and clear the Counselors Private GC.',
        );

        $archiveDir = (string) config('cpf.archive_path', storage_path('app/archives'));
        $result = $action->handle($user, $membership->campus_id, $archiveDir);

        return response()->json([
            'status' => 'archived',
            'archive' => $result['archive'],
            'total_messages' => $result['total_messages'],
        ]);
    }

    public function sendCounselorGcMessage(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user?->verifiedRole();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->canCoordinateGuidance() && $membership !== null,
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
