<?php

namespace App\Http\Controllers;

use App\Actions\Gc\ArchiveAndResetOfficerGc;
use App\Actions\Gc\ArchiveCounselorGc;
use App\Actions\Security\IssueMemberPasskey;
use App\Actions\Support\RequestTestingRoom;
use App\Actions\Support\SubmitGuidanceReferral;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Events\CounselorDmMessageSent;
use App\Events\CounselorGcMessageSent;
use App\Events\OfficerGcMessageSent;
use App\Models\CampusCounselor;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\CounselorDirectMessage;
use App\Models\CounselorGcMessage;
use App\Models\GuidanceReferral;
use App\Models\OfficerGcChannel;
use App\Models\OfficerGcMessage;
use App\Models\ReferralAuditLog;
use App\Models\RoomRequest;
use App\Models\User;
use App\Services\ProfanityFilterService;
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
        $user = $request->user();
        abort_if(
            $user?->isAlumni(),
            403,
            'Former member and officer accounts have read-only archive access.',
        );

        $membership = $user?->primaryMembership;
        abort_unless(
            $user !== null
            && $user->verifiedRole() !== null
            && $membership !== null
            && $membership->status === MembershipStatus::Approved
            && $membership->campus_id !== null,
            403,
            'An approved campus membership is required for Testing Room requests.',
        );

        $validated = $request->validate([
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'counselor_id' => ['nullable', 'integer', 'exists:campus_counselors,id'],
            'purpose' => ['required', 'string', 'max:200'],
            'request_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        app(ProfanityFilterService::class)->assertClean((string) $validated['purpose'], 'purpose');

        $campusId = $validated['campus_id'] ?? $membership->campus_id;
        abort_unless(
            (int) $campusId === $membership->campus_id,
            403,
            'Testing Room requests must be submitted to your assigned campus chapter.',
        );

        $campusId = $membership->campus_id;

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

        $roomRequest = $action->handle($user, $campusId, $validated);

        return response()->json(['status' => 'pending', 'room_request' => $roomRequest], 201);
    }

    public function reviewTestingRoom(Request $request, RoomRequest $roomRequest): JsonResponse
    {
        $user = $request->user();
        $role = $user?->verifiedRole();
        $membership = $user?->primaryMembership;

        $isCounselingBooking = $roomRequest->counselor_id !== null
            || GuidanceReferral::query()->where('room_request_id', $roomRequest->id)->exists();

        if ($isCounselingBooking) {
            abort_unless(
                $role && $role->canCoordinateGuidance() && $membership && $membership->campus_id === $roomRequest->campus_id,
                403,
                'Only the Club Adviser or a campus Guidance Counselor can review counseling Testing Room requests.',
            );
        } else {
            abort_unless(
                ($user?->isActiveOfficerOrAdviser() || $user?->canCoordinateGuidance()) && $membership && $membership->campus_id === $roomRequest->campus_id,
                403,
                'Only verified campus officers, Guidance Counselors, or the Club Adviser can review Testing Room requests.',
            );
        }

        $validated = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'review_notes' => ['nullable', 'string', 'max:255'],
        ]);

        if (filled($validated['review_notes'] ?? null)) {
            app(ProfanityFilterService::class)->assertClean((string) $validated['review_notes'], 'review_notes');
        }

        $updated = DB::transaction(function () use ($roomRequest, $validated, $user, $role): RoomRequest {
            $lockedRequest = RoomRequest::query()
                ->whereKey($roomRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedIsCounseling = $lockedRequest->counselor_id !== null
                || GuidanceReferral::query()->where('room_request_id', $lockedRequest->id)->exists();

            abort_if(
                $lockedIsCounseling && ! $role->canCoordinateGuidance(),
                403,
                'Only the Club Adviser or a campus Guidance Counselor can review counseling Testing Room requests.',
            );

            abort_unless(
                $lockedRequest->status === 'pending',
                422,
                'Only pending Testing Room requests can be reviewed.',
            );

            if ($validated['status'] === 'approved') {
                if ($lockedRequest->room_id !== null) {
                    CampusRoom::query()->whereKey($lockedRequest->room_id)->lockForUpdate()->first();
                }

                $conflict = RoomRequest::query()
                    ->where('campus_id', $lockedRequest->campus_id)
                    ->where('room_id', $lockedRequest->room_id)
                    ->where('id', '!=', $lockedRequest->id)
                    ->whereDate('request_date', $lockedRequest->request_date)
                    ->where('status', 'approved')
                    ->where('start_time', '<', $lockedRequest->end_time)
                    ->where('end_time', '>', $lockedRequest->start_time)
                    ->lockForUpdate()
                    ->exists();

                abort_if(
                    $conflict,
                    409,
                    'This Testing Room slot conflicts with an already-approved reservation.',
                );
            }

            $lockedRequest->update([
                'status' => $validated['status'],
                'reviewed_by_user_id' => $user->id,
                'review_notes' => $validated['review_notes'] ?? null,
            ]);

            return $lockedRequest->fresh();
        });

        return response()->json([
            'status' => $updated->status,
            'room_request' => $updated,
        ]);
    }

    public function submitReferral(Request $request, SubmitGuidanceReferral $action): JsonResponse
    {
        $user = $request->user();
        abort_if(
            $user?->isAlumni(),
            403,
            'Former member and officer accounts have read-only archive access.',
        );

        $membership = $user?->primaryMembership;
        abort_unless(
            $user !== null
            && $user->verifiedRole() !== null
            && $membership !== null
            && $membership->status === MembershipStatus::Approved
            && $membership->campus_id !== null,
            403,
            'An approved campus membership is required to submit a guidance referral.',
        );

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

        abort_unless(
            (int) $validated['campus_id'] === $membership->campus_id,
            403,
            'Referrals must be submitted to your assigned campus chapter.',
        );

        $campusId = $membership->campus_id;

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

        $referral = $action->handle($user, $campusId, $validated, $request->ip());

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

            if ($referral->room_request_id !== null && $feedbackStatus === 'completed') {
                $pendingRoomRequest = RoomRequest::query()
                    ->whereKey($referral->room_request_id)
                    ->lockForUpdate()
                    ->first();

                if ($pendingRoomRequest !== null && $pendingRoomRequest->status === 'pending') {
                    $pendingRoomRequest->update([
                        'status' => 'rejected',
                        'reviewed_by_user_id' => $user->id,
                        'review_notes' => 'Auto-released upon direct referral completion.',
                    ]);
                }
            }

            if ($referral->room_request_id !== null && $feedbackStatus === 'ongoing') {
                $linkedRoomRequest = RoomRequest::query()
                    ->whereKey($referral->room_request_id)
                    ->lockForUpdate()
                    ->first();

                if ($linkedRoomRequest !== null && $linkedRoomRequest->status === 'pending') {
                    if ($linkedRoomRequest->room_id !== null) {
                        CampusRoom::query()->whereKey($linkedRoomRequest->room_id)->lockForUpdate()->first();
                    }

                    $conflict = RoomRequest::query()
                        ->where('campus_id', $linkedRoomRequest->campus_id)
                        ->where('room_id', $linkedRoomRequest->room_id)
                        ->where('id', '!=', $linkedRoomRequest->id)
                        ->whereDate('request_date', $linkedRoomRequest->request_date)
                        ->where('status', 'approved')
                        ->where('start_time', '<', $linkedRoomRequest->end_time)
                        ->where('end_time', '>', $linkedRoomRequest->start_time)
                        ->lockForUpdate()
                        ->exists();

                    abort_if(
                        $conflict,
                        409,
                        'The suggested Testing Room slot was already taken by another approved booking.',
                    );

                    $linkedRoomRequest->update([
                        'status' => 'approved',
                        'reviewed_by_user_id' => $user->id,
                    ]);
                }
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

    public function fetchOfficerGcMessages(Request $request, OfficerGcChannel $channel): JsonResponse
    {
        $user = $request->user();
        $role = $user?->verifiedRole();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isActiveOfficerOrAdviser() && $membership && $membership->campus_id === $channel->campus_id,
            403,
            'Only active campus Officers and the Club Adviser may read this Officers Private GC.',
        );

        $afterId = max(0, (int) $request->query('after_id', 0));
        $messages = $channel->messages()
            ->with('sender:id,name')
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (OfficerGcMessage $msg) => [
                'id' => $msg->id,
                'sender_user_id' => $msg->sender_user_id,
                'sender_name' => $msg->sender?->name ?? 'Officer',
                'sender' => $msg->sender ? ['id' => $msg->sender->id, 'name' => $msg->sender->name] : null,
                'sender_role' => $msg->sender_role,
                'message_body' => $msg->message_body,
                'created_at' => (string) $msg->created_at,
            ])
            ->values();

        return response()->json(['messages' => $messages]);
    }

    public function sendOfficerGcMessage(Request $request, OfficerGcChannel $channel, ProfanityFilterService $profanityFilter): JsonResponse
    {
        $user = $request->user();
        $role = $user?->verifiedRole();
        $membership = $user?->primaryMembership;

        abort_if(
            (bool) config('cpf.features.officers_gc_paused', false),
            423,
            'Officers Private GC is currently paused by chapter configuration.',
        );

        abort_unless(
            $user?->isActiveOfficerOrAdviser() && $membership && $membership->campus_id === $channel->campus_id,
            403,
            'Only active campus Officers and the Club Adviser may message this Officers Private GC.',
        );

        $validated = $request->validate(['message_body' => ['required', 'string', 'max:2000']]);
        $profanityFilter->assertClean((string) $validated['message_body'], 'message_body');

        $msg = DB::transaction(function () use ($channel, $user, $role, $validated): OfficerGcMessage {
            $lockedChannel = OfficerGcChannel::query()
                ->whereKey($channel->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                (bool) $lockedChannel->is_active,
                403,
                'This Officers Private GC channel is no longer active for messaging.',
            );

            return OfficerGcMessage::create([
                'channel_id' => $lockedChannel->id,
                'sender_user_id' => $user->id,
                'sender_role' => $role->value,
                'message_body' => trim($validated['message_body']),
            ]);
        });

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

    public function fetchCounselorGcMessages(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->canCoordinateGuidance() && $membership !== null,
            403,
            'Counselors Private GC is restricted to the campus Club Adviser and Guidance Counselors.',
        );

        $afterId = max(0, (int) $request->query('after_id', 0));
        $messages = CounselorGcMessage::query()
            ->with('sender:id,name')
            ->where('campus_id', $membership->campus_id)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (CounselorGcMessage $msg) => [
                'id' => $msg->id,
                'sender_user_id' => $msg->sender_user_id,
                'sender_name' => $msg->sender?->name ?? 'Guidance Counselor',
                'sender' => $msg->sender ? ['id' => $msg->sender->id, 'name' => $msg->sender->name] : null,
                'sender_role' => $msg->sender_role,
                'message_body' => $msg->message_body,
                'created_at' => (string) $msg->created_at,
            ])
            ->values();

        return response()->json(['messages' => $messages]);
    }

    public function sendCounselorGcMessage(Request $request, ProfanityFilterService $profanityFilter): JsonResponse
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
        $profanityFilter->assertClean((string) $validated['message_body'], 'message_body');

        $msg = CounselorGcMessage::create([
            'campus_id' => $membership->campus_id,
            'sender_user_id' => $user->id,
            'sender_role' => $role?->value ?? 'adviser',
            'message_body' => trim($validated['message_body']),
        ]);

        $msg->load('sender:id,name');
        CounselorGcMessageSent::dispatch((int) $membership->campus_id, $msg);

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

    public function fetchCounselorDmMessages(Request $request, User $peerUser): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;
        $peerMembership = $peerUser->primaryMembership;

        abort_unless(
            $user?->canCoordinateGuidance()
            && $membership !== null
            && $peerUser->canCoordinateGuidance()
            && $peerMembership !== null
            && $peerMembership->campus_id === $membership->campus_id
            && $peerUser->id !== $user->id,
            403,
            'Direct Counselor Chat is restricted to verified Guidance Counselors and the Club Adviser of the same campus.',
        );

        $threadKey = CounselorDirectMessage::makeThreadKey($user->id, $peerUser->id);
        $afterId = max(0, (int) $request->query('after_id', 0));

        $messages = CounselorDirectMessage::query()
            ->with('sender:id,name')
            ->where('campus_id', $membership->campus_id)
            ->where('thread_key', $threadKey)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (CounselorDirectMessage $msg) => [
                'id' => $msg->id,
                'thread_key' => $msg->thread_key,
                'sender_user_id' => $msg->sender_user_id,
                'recipient_user_id' => $msg->recipient_user_id,
                'sender_name' => $msg->sender?->name ?? 'Guidance Counselor',
                'sender' => $msg->sender ? ['id' => $msg->sender->id, 'name' => $msg->sender->name] : null,
                'sender_role' => $msg->sender_role,
                'message_body' => $msg->message_body,
                'created_at' => (string) $msg->created_at,
            ])
            ->values();

        return response()->json(['thread_key' => $threadKey, 'messages' => $messages]);
    }

    public function sendCounselorDmMessage(Request $request, User $peerUser, ProfanityFilterService $profanityFilter): JsonResponse
    {
        $user = $request->user();
        $role = $user?->verifiedRole();
        $membership = $user?->primaryMembership;
        $peerMembership = $peerUser->primaryMembership;

        abort_unless(
            $user?->canCoordinateGuidance()
            && $membership !== null
            && $peerUser->canCoordinateGuidance()
            && $peerMembership !== null
            && $peerMembership->campus_id === $membership->campus_id
            && $peerUser->id !== $user->id,
            403,
            'Direct Counselor Chat is restricted to verified Guidance Counselors and the Club Adviser of the same campus.',
        );

        $validated = $request->validate(['message_body' => ['required', 'string', 'max:2000']]);
        $profanityFilter->assertClean((string) $validated['message_body'], 'message_body');

        $threadKey = CounselorDirectMessage::makeThreadKey($user->id, $peerUser->id);

        $msg = CounselorDirectMessage::create([
            'campus_id' => $membership->campus_id,
            'thread_key' => $threadKey,
            'sender_user_id' => $user->id,
            'recipient_user_id' => $peerUser->id,
            'sender_role' => $role?->value ?? 'guidance_counselor',
            'message_body' => trim($validated['message_body']),
        ]);

        $msg->load('sender:id,name');
        CounselorDmMessageSent::dispatch($msg);

        return response()->json([
            'status' => 'sent',
            'thread_key' => $threadKey,
            'message' => [
                'id' => $msg->id,
                'thread_key' => $msg->thread_key,
                'sender_user_id' => $msg->sender_user_id,
                'recipient_user_id' => $msg->recipient_user_id,
                'sender_name' => $msg->sender?->name ?? $user->name,
                'sender' => ['id' => $user->id, 'name' => $msg->sender?->name ?? $user->name],
                'sender_role' => $msg->sender_role,
                'message_body' => $msg->message_body,
                'created_at' => (string) $msg->created_at,
            ],
        ], 201);
    }
}
