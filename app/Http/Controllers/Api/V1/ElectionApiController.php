<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CpfV4Controller;
use App\Models\CampusMembership;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionOptOut;
use App\Models\ElectionVote;
use App\Models\User;
use App\Support\CpfElectionPositions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ElectionApiController extends Controller
{
    public const MAX_LIVE_CHAT_MESSAGES = 100;

    public const LIVE_CHAT_TTL_SECONDS = 7200; // 2 hours

    /**
     * GET /api/v1/elections/active
     * Returns the campus's active open election, ordered parliamentary seats,
     * nominees, and personal voting / nomination status.
     */
    public function active(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $role = $user->verifiedRole();

        abort_if(
            $role === CpfRole::GuidanceCounselor,
            403,
            'Guidance Counselors have coordination-only access and do not participate in student club elections.',
        );

        $membership = $user->primaryMembership()->with('campus:id,code,name')->first();

        abort_unless(
            $role !== null && $membership !== null && $membership->campus !== null,
            403,
            'Only verified campus members and the Club Adviser may access campus elections.',
        );

        $campus = $membership->campus;
        $seatsOrdered = CpfElectionPositions::ordered();

        /** @var Election|null $election */
        $election = Election::query()
            ->where('campus_id', $campus->id)
            ->where('status', 'open')
            ->where(function ($query): void {
                $query->whereNull('current_phase')
                    ->orWhere('current_phase', '!=', 'completed');
            })
            ->latest('id')
            ->first();

        if (! $election || ! $election->isSessionActive()) {
            if (
                $election
                && (
                    $election->status !== 'open'
                    || $election->current_phase === 'completed'
                    || ($election->closes_at !== null && now()->gt($election->closes_at))
                )
            ) {
                Election::flushLiveChatCache((int) $election->id);
            }

            return response()->json([
                'campus' => [
                    'id' => $campus->id,
                    'code' => $campus->code,
                    'name' => $campus->name,
                ],
                'seats' => $seatsOrdered,
                'active_election' => null,
            ]);
        }

        $activeRound = (int) ($election->round_number ?: 1);
        $activePosition = $election->current_position;
        $phase = $election->current_phase ?: 'voting';
        $positionResults = $election->position_results ?? [];

        $candidatesRaw = ElectionCandidate::query()
            ->where('election_id', $election->id)
            ->orderBy('id')
            ->get();

        $candidateUserIds = $candidatesRaw->pluck('user_id')->unique();

        $memberByUserId = CampusMembership::query()
            ->with('user:id,name,profile_photo_path,updated_at')
            ->where('campus_id', $campus->id)
            ->where('status', MembershipStatus::Approved->value)
            ->whereIn('user_id', $candidateUserIds)
            ->get()
            ->keyBy('user_id');

        $fallbackUserNames = User::query()
            ->whereIn('id', $candidateUserIds)
            ->pluck('name', 'id');

        $voteCounts = ElectionVote::query()
            ->where('election_id', $election->id)
            ->selectRaw('candidate_id, round_number, COUNT(*) as total')
            ->groupBy('candidate_id', 'round_number')
            ->get();

        $nominees = $candidatesRaw->map(function (ElectionCandidate $c) use (
            $memberByUserId,
            $fallbackUserNames,
            $voteCounts,
            $activePosition,
            $activeRound,
        ): array {
            /** @var CampusMembership|null $m */
            $m = $memberByUserId->get($c->user_id);
            $targetRound = ($c->position === $activePosition) ? $activeRound : 1;
            $voteRow = $voteCounts->first(
                fn ($row): bool => (int) $row->candidate_id === $c->id && (int) $row->round_number === $targetRound,
            );

            return [
                'id' => $c->id,
                'user_id' => $c->user_id,
                'name' => $m?->full_name_last_first_mi ?? ($fallbackUserNames->get($c->user_id) ?? 'CPF Member'),
                'course_year_section' => $m?->course_year_section ?? 'Campus Member',
                'year_level' => CpfElectionPositions::normalizeYearDigit($m?->year_level),
                'avatar_url' => $m?->user?->avatar,
                'position' => $c->position,
                'platform' => $c->platform,
                'nominated_by_user_id' => $c->nominated_by_user_id,
                'nomination_status' => $c->nomination_status ?: 'accepted',
                'is_tie_contender' => (bool) $c->is_tie_contender,
                'votes' => (int) ($voteRow->total ?? 0),
            ];
        })->values();

        $myVotes = ElectionVote::query()
            ->where('election_id', $election->id)
            ->where('voter_user_id', $user->id)
            ->get()
            ->filter(fn (ElectionVote $v): bool => $v->position !== $activePosition || (int) ($v->round_number ?: 1) === $activeRound)
            ->pluck('candidate_id', 'position')
            ->all();

        $currentVoteCandidateId = $activePosition !== null && isset($myVotes[$activePosition])
            ? (int) $myVotes[$activePosition]
            : null;

        $hasOptedOut = ElectionOptOut::query()
            ->where('election_id', $election->id)
            ->where('user_id', $user->id)
            ->exists();

        $seatsWithStatus = array_map(function (array $seat) use ($positionResults, $activePosition): array {
            $key = $seat['key'];

            return [
                ...$seat,
                'is_current' => $key === $activePosition,
                'result' => $positionResults[$key] ?? null,
            ];
        }, $seatsOrdered);

        $currentSeatMeta = CpfElectionPositions::find($activePosition);
        $canVote = ! $user->isClubAdviser()
            && in_array($phase, ['voting', 'tie_breaker'], true)
            && $activePosition !== null;

        return response()->json([
            'campus' => [
                'id' => $campus->id,
                'code' => $campus->code,
                'name' => $campus->name,
            ],
            'seats' => $seatsWithStatus,
            'active_election' => [
                'id' => $election->id,
                'title' => $election->title,
                'academic_year' => $election->academic_year,
                'status' => $election->status,
                'current_position' => $activePosition,
                'current_position_label' => $currentSeatMeta['label'] ?? $activePosition,
                'current_phase' => $phase,
                'round_number' => $activeRound,
                'opens_at' => $election->opens_at?->toIso8601String(),
                'closes_at' => $election->closes_at?->toIso8601String(),
                'position_results' => $positionResults,
                'nominees' => $nominees,
                'current_position_nominees' => $nominees
                    ->where('position', $activePosition)
                    ->values(),
                'voting_status' => [
                    'can_nominate' => $phase === 'nomination' && $activePosition !== null,
                    'can_vote' => $canVote,
                    'has_voted_current_round' => $currentVoteCandidateId !== null,
                    'my_current_vote_candidate_id' => $currentVoteCandidateId,
                    'my_votes' => $myVotes,
                    'has_opted_out' => $hasOptedOut,
                ],
                'live_chat' => [
                    'enabled' => true,
                    'ephemeral' => true,
                    'max_messages' => self::MAX_LIVE_CHAT_MESSAGES,
                    'ttl_seconds' => self::LIVE_CHAT_TTL_SECONDS,
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/elections/{election}/nominate
     */
    public function nominate(
        Request $request,
        Election $election,
        CpfV4Controller $v4Controller,
    ): JsonResponse {
        return $v4Controller->nominateCandidate($request, $election);
    }

    /**
     * POST /api/v1/elections/{election}/vote
     */
    public function vote(
        Request $request,
        Election $election,
        CpfV4Controller $v4Controller,
    ): JsonResponse {
        if (! $request->filled('position') && $election->current_position !== null) {
            $request->merge(['position' => $election->current_position]);
        }

        return $v4Controller->castVote($request, $election);
    }

    /**
     * GET /api/v1/elections/{election}/live-chat
     * Reads ephemeral session chat messages from the non-DB `ephemeral_chat`
     * cache store ONLY while the election session is active.
     */
    public function liveChatIndex(Request $request, Election $election): JsonResponse
    {
        $this->authorizeAndAssertActiveSession($request, $election);

        $cacheKey = Election::liveChatCacheKey((int) $election->id);
        $messages = Election::liveChatCache()->get($cacheKey, []);
        if (! is_array($messages)) {
            $messages = [];
        }

        return response()->json([
            'election_id' => $election->id,
            'ephemeral' => true,
            'max_messages' => self::MAX_LIVE_CHAT_MESSAGES,
            'ttl_seconds' => self::LIVE_CHAT_TTL_SECONDS,
            'messages' => array_values($messages),
        ]);
    }

    /**
     * POST /api/v1/elections/{election}/live-chat
     * Appends an ephemeral message to the non-DB `ephemeral_chat` cache store
     * (`election:{id}:live_chat`, max 100 messages, 2h TTL capped at closes_at).
     * Never writes to the database.
     */
    public function liveChatStore(Request $request, Election $election): JsonResponse
    {
        [$user, $membership, $role] = $this->authorizeAndAssertActiveSession($request, $election);

        if (! $request->filled('message_body') && $request->filled('message')) {
            $request->merge(['message_body' => $request->input('message')]);
        }

        $validated = $request->validate([
            'message_body' => ['required', 'string', 'max:500'],
        ]);

        $body = trim((string) $validated['message_body']);
        abort_if($body === '', 422, 'Message body cannot be empty.');

        $cache = Election::liveChatCache();
        $cacheKey = Election::liveChatCacheKey((int) $election->id);
        $existing = $cache->get($cacheKey, []);
        if (! is_array($existing)) {
            $existing = [];
        }

        $newMessage = [
            'id' => (string) Str::uuid(),
            'election_id' => $election->id,
            'sender_user_id' => $user->id,
            'sender_name' => $membership->full_name_last_first_mi ?: $user->name,
            'sender_role' => $role->value,
            'sender_role_label' => $role->label(),
            'message_body' => $body,
            'sent_at' => now()->toIso8601String(),
        ];

        $existing[] = $newMessage;
        if (count($existing) > self::MAX_LIVE_CHAT_MESSAGES) {
            $existing = array_slice($existing, -self::MAX_LIVE_CHAT_MESSAGES);
        }
        $existing = array_values($existing);

        $expiresAt = now()->addSeconds(self::LIVE_CHAT_TTL_SECONDS);
        if ($election->closes_at !== null && $election->closes_at->lt($expiresAt)) {
            $expiresAt = $election->closes_at;
        }

        $cache->put($cacheKey, $existing, $expiresAt);

        return response()->json([
            'status' => 'sent',
            'ephemeral' => true,
            'ttl_seconds' => self::LIVE_CHAT_TTL_SECONDS,
            'message' => $newMessage,
            'messages_count' => count($existing),
        ], 201);
    }

    /**
     * @return array{0: User, 1: CampusMembership, 2: CpfRole}
     */
    private function authorizeAndAssertActiveSession(Request $request, Election $election): array
    {
        /** @var User $user */
        $user = $request->user();
        $role = $user->verifiedRole();

        abort_if(
            $role === CpfRole::GuidanceCounselor,
            403,
            'Guidance Counselors have coordination-only access and do not participate in student club elections.',
        );

        $membership = $user->primaryMembership;

        abort_unless(
            $role !== null && $membership !== null && $membership->campus_id === $election->campus_id,
            403,
            'Only verified members and the Club Adviser of this campus may access election session chat.',
        );

        if (! $election->isSessionActive()) {
            if (
                $election->status !== 'open'
                || $election->current_phase === 'completed'
                || ($election->closes_at !== null && now()->gt($election->closes_at))
            ) {
                Election::flushLiveChatCache((int) $election->id);
            }

            abort(422, 'No active election session is open for live chat.');
        }

        return [$user, $membership, $role];
    }
}
