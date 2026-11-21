<?php

namespace App\Http\Controllers;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusMembership;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionOptOut;
use App\Models\ElectionVote;
use App\Models\MemberAchievement;
use App\Models\PaymentDestination;
use App\Models\PaymentSubmission;
use App\Models\PeerQuote;
use App\Models\TimeCapsule;
use App\Support\CpfElectionPositions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CpfV4Controller extends Controller
{
    public function storeAchievement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:60'],
            'achieved_on' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $item = MemberAchievement::create([...$validated, 'user_id' => $request->user()->id]);

        return response()->json(['status' => 'created', 'achievement' => $item], 201);
    }

    public function storeTimeCapsule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:4000'],
            'unlock_at' => ['required', 'date', 'after:now'],
        ]);

        $capsule = TimeCapsule::create([
            'campus_id' => $validated['campus_id'],
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'encrypted_message' => $validated['message'],
            'unlock_at' => $validated['unlock_at'],
        ]);

        return response()->json(['status' => 'sealed', 'capsule_id' => $capsule->id], 201);
    }

    public function openTimeCapsule(Request $request, TimeCapsule $capsule): JsonResponse
    {
        abort_unless($capsule->user_id === $request->user()->id, 403, 'You can only open your own time capsule.');
        abort_if(
            now()->lt($capsule->unlock_at),
            422,
            'This time capsule is date-locked until '.$capsule->unlock_at->toDateTimeString().'.',
        );

        if (! $capsule->is_opened) {
            $capsule->update([
                'is_opened' => true,
                'opened_at' => now(),
            ]);
        }

        return response()->json([
            'status' => 'opened',
            'capsule' => [
                'id' => $capsule->id,
                'title' => $capsule->title,
                'message' => $capsule->encrypted_message,
                'opened_at' => $capsule->opened_at?->toIso8601String(),
            ],
        ]);
    }

    public function storeElection(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership !== null,
            403,
            'Only the Club Adviser can activate campus elections.',
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'academic_year' => ['required', 'string', 'max:20'],
            'status' => ['nullable', 'in:draft,open,closed'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:opens_at'],
        ]);

        $election = Election::create([
            'campus_id' => $membership->campus_id,
            'academic_year' => trim($validated['academic_year']),
            'title' => trim($validated['title']),
            'status' => $validated['status'] ?? 'open',
            'current_position' => 'president',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [],
            'opens_at' => $validated['opens_at'] ?? null,
            'closes_at' => $validated['closes_at'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json(['status' => 'created', 'election' => $election], 201);
    }

    public function storeCandidate(Request $request, Election $election): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $election->campus_id,
            403,
            'Only the campus Club Adviser can add election candidates.',
        );

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'position' => ['required', Rule::in(CpfElectionPositions::keys())],
            'platform' => ['nullable', 'string', 'max:1000'],
        ]);

        $member = $this->validateNomineeEligibility($election, (int) $validated['user_id'], $validated['position']);

        $candidate = ElectionCandidate::updateOrCreate(
            [
                'election_id' => $election->id,
                'user_id' => $member->user_id,
                'position' => $validated['position'],
            ],
            [
                'platform' => isset($validated['platform']) ? trim($validated['platform']) : null,
                'nominated_by_user_id' => $user->id,
                'nomination_status' => 'accepted',
                'is_tie_contender' => false,
            ],
        );

        return response()->json(['status' => 'added', 'candidate' => $candidate], 201);
    }

    public function nominateCandidate(Request $request, Election $election): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->verifiedRole() !== null && $membership?->campus_id === $election->campus_id,
            403,
            'Only verified members of this campus may nominate candidates.',
        );

        abort_unless(
            $election->status === 'open' && $election->current_phase === 'nomination' && $election->current_position !== null,
            422,
            'Nominations are not currently open.',
        );

        $validated = $request->validate([
            'nominee_user_id' => ['required', 'integer', 'exists:users,id'],
            'platform' => ['nullable', 'string', 'max:1000'],
        ]);

        $position = (string) $election->current_position;
        $nomineeUserId = (int) $validated['nominee_user_id'];

        $this->validateNomineeEligibility($election, $nomineeUserId, $position);

        $existing = ElectionCandidate::query()
            ->where('election_id', $election->id)
            ->where('user_id', $nomineeUserId)
            ->where('position', $position)
            ->first();

        if ($existing && $existing->nomination_status === 'accepted') {
            return response()->json(['status' => 'already_accepted', 'candidate' => $existing], 200);
        }

        $nominationStatus = ($nomineeUserId === $user->id) ? 'accepted' : 'pending';

        $candidate = ElectionCandidate::updateOrCreate(
            [
                'election_id' => $election->id,
                'user_id' => $nomineeUserId,
                'position' => $position,
            ],
            [
                'platform' => isset($validated['platform']) ? trim($validated['platform']) : ($existing?->platform ?? null),
                'nominated_by_user_id' => $user->id,
                'nomination_status' => $nominationStatus,
                'is_tie_contender' => false,
            ],
        );

        return response()->json(['status' => 'nominated', 'candidate' => $candidate], 201);
    }

    public function respondNomination(Request $request, Election $election, ElectionCandidate $candidate): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $candidate->election_id === $election->id
                && $candidate->user_id === $user?->id
                && $membership?->campus_id === $election->campus_id,
            403,
            'You can only respond to your own campus election nomination.',
        );

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
        ]);

        if ($validated['decision'] === 'accepted') {
            $optedOut = ElectionOptOut::query()
                ->where('election_id', $election->id)
                ->where('user_id', $user->id)
                ->exists();

            abort_if(
                $optedOut,
                422,
                'You opted out of nominations. Re-enable your eligibility first before accepting.',
            );

            $candidate->update(['nomination_status' => 'accepted']);
        } else {
            DB::transaction(function () use ($election, $candidate, $user): void {
                $candidate->update(['nomination_status' => 'declined']);

                ElectionOptOut::query()->firstOrCreate(
                    [
                        'election_id' => $election->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'opted_out_at' => now(),
                    ],
                );

                ElectionCandidate::query()
                    ->where('election_id', $election->id)
                    ->where('user_id', $user->id)
                    ->where('nomination_status', 'pending')
                    ->update(['nomination_status' => 'declined']);
            });
        }

        return response()->json([
            'status' => $validated['decision'],
            'candidate' => $candidate->fresh(),
        ]);
    }

    public function optInNominations(Request $request, Election $election): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->verifiedRole() !== null && $membership?->campus_id === $election->campus_id,
            403,
            'Only verified members of this campus may update nomination eligibility.',
        );

        ElectionOptOut::query()
            ->where('election_id', $election->id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['status' => 'opted_in']);
    }

    public function closeNominations(Request $request, Election $election): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $election->campus_id,
            403,
            'Only the campus Club Adviser can close nominations.',
        );

        abort_unless(
            $election->status === 'open' && $election->current_phase === 'nomination' && $election->current_position !== null,
            422,
            'No active nomination round is open.',
        );

        $position = (string) $election->current_position;

        $accepted = ElectionCandidate::query()
            ->where('election_id', $election->id)
            ->where('position', $position)
            ->where('nomination_status', 'accepted')
            ->get();

        if ($accepted->count() >= 2) {
            DB::transaction(function () use ($election, $position): void {
                ElectionCandidate::query()
                    ->where('election_id', $election->id)
                    ->where('position', $position)
                    ->where('nomination_status', 'pending')
                    ->update(['nomination_status' => 'declined']);

                $election->update([
                    'current_phase' => 'voting',
                    'round_number' => 1,
                ]);
            });

            return response()->json([
                'status' => 'voting_opened',
                'election' => $election->fresh(),
            ]);
        }

        if ($accepted->count() === 1) {
            abort_unless(
                $user->isClubAdviser(),
                403,
                'Need at least 2 accepted candidates for a member to close nominations; only the Club Adviser can close an unopposed position.',
            );

            $this->recordWinnerAndAdvance($election, $position, $accepted->first(), 0, 'unopposed');

            return response()->json([
                'status' => 'elected_unopposed',
                'election' => $election->fresh(),
            ]);
        }

        abort(422, 'Need at least 2 accepted candidates to close nominations (or the Club Adviser can skip an optional Year Representative seat).');
    }

    public function skipOptionalPosition(Request $request, Election $election): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $election->campus_id,
            403,
            'Only the campus Club Adviser can skip an optional representative position.',
        );

        abort_unless(
            $election->status === 'open' && $election->current_phase === 'nomination' && $election->current_position !== null,
            422,
            'Skipping is only available during an active nomination round.',
        );

        $position = (string) $election->current_position;

        abort_unless(
            CpfElectionPositions::isOptional($position),
            422,
            'Only Year-Level Representative seats are optional and may be skipped.',
        );

        $results = $election->position_results ?? [];
        $results[$position] = [
            'status' => 'skipped',
            'winner_user_id' => null,
            'winner_name' => null,
            'course_year_section' => null,
            'votes' => 0,
            'won_by' => 'skipped',
        ];

        $this->advanceElectionPosition($election, $position, $results);

        return response()->json([
            'status' => 'position_skipped',
            'election' => $election->fresh(),
        ]);
    }

    public function updateElectionStatus(Request $request, Election $election): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $election->campus_id,
            403,
            'Only the campus Club Adviser can change election status.',
        );

        $validated = $request->validate([
            'status' => ['required', 'in:draft,open,closed'],
        ]);

        $election->update(['status' => $validated['status']]);

        return response()->json(['status' => $election->status, 'election' => $election]);
    }

    public function castVote(Request $request, Election $election): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->verifiedRole() !== null && $membership?->campus_id === $election->campus_id,
            403,
            'Only verified members of this campus may cast a vote.',
        );

        abort_unless($election->status === 'open', 422, 'Election is not currently open.');
        abort_if($election->opens_at !== null && now()->lt($election->opens_at), 422, 'Election voting has not opened yet.');
        abort_if($election->closes_at !== null && now()->gt($election->closes_at), 422, 'Election voting has already closed.');

        $phase = $election->current_phase ?: 'voting';
        abort_unless(
            in_array($phase, ['voting', 'tie_breaker'], true),
            422,
            'Voting is not currently open for this position.',
        );

        $validated = $request->validate([
            'candidate_id' => ['required', 'integer', 'exists:election_candidates,id'],
            'position' => ['required', 'string', 'max:60'],
        ]);

        $candidate = ElectionCandidate::query()
            ->where('id', $validated['candidate_id'])
            ->where('election_id', $election->id)
            ->where('position', $validated['position'])
            ->where('nomination_status', 'accepted')
            ->first();

        abort_unless($candidate !== null, 422, 'Selected candidate is not eligible for this election and position.');

        if ($phase === 'tie_breaker' && $validated['position'] === $election->current_position) {
            abort_unless(
                (bool) $candidate->is_tie_contender,
                422,
                'Only tied contenders are eligible in this tie-breaker runoff round.',
            );
        }

        $roundNumber = ($validated['position'] === $election->current_position)
            ? (int) ($election->round_number ?: 1)
            : 1;

        $vote = ElectionVote::firstOrCreate(
            [
                'election_id' => $election->id,
                'voter_user_id' => $user->id,
                'position' => $validated['position'],
                'round_number' => $roundNumber,
            ],
            [
                'candidate_id' => $validated['candidate_id'],
                'cast_at' => now(),
            ],
        );

        return response()->json(['status' => $vote->wasRecentlyCreated ? 'voted' : 'already_voted']);
    }

    public function finalizePosition(Request $request, Election $election): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $election->campus_id,
            403,
            'Only the campus Club Adviser can finalize position results.',
        );

        abort_unless(
            $election->status === 'open'
                && in_array($election->current_phase, ['voting', 'tie_breaker'], true)
                && $election->current_position !== null,
            422,
            'Position is not currently in an active voting phase.',
        );

        $validated = $request->validate([
            'tie_resolution' => ['nullable', Rule::in(['runoff', 'adviser_tiebreak', 'draw_lots'])],
            'winner_candidate_id' => ['nullable', 'integer', 'exists:election_candidates,id'],
        ]);

        $position = (string) $election->current_position;
        $roundNumber = (int) ($election->round_number ?: 1);

        $eligibleQuery = ElectionCandidate::query()
            ->where('election_id', $election->id)
            ->where('position', $position)
            ->where('nomination_status', 'accepted');

        if ($election->current_phase === 'tie_breaker') {
            $eligibleQuery->where('is_tie_contender', true);
        }

        /** @var Collection<int, ElectionCandidate> $candidates */
        $candidates = $eligibleQuery->get();
        abort_if($candidates->isEmpty(), 422, 'No eligible candidates exist for this position.');

        $tallies = ElectionVote::query()
            ->where('election_id', $election->id)
            ->where('position', $position)
            ->where('round_number', $roundNumber)
            ->selectRaw('candidate_id, COUNT(*) as total')
            ->groupBy('candidate_id')
            ->pluck('total', 'candidate_id');

        $totalVotes = $candidates->sum(fn (ElectionCandidate $c) => (int) $tallies->get($c->id, 0));
        $maxVotes = $candidates->max(fn (ElectionCandidate $c) => (int) $tallies->get($c->id, 0)) ?? 0;
        $leaders = $candidates->filter(fn (ElectionCandidate $c) => (int) $tallies->get($c->id, 0) === $maxVotes)->values();

        $resolution = $validated['tie_resolution'] ?? null;

        if ($totalVotes === 0 && ! ($election->current_phase === 'tie_breaker' && in_array($resolution, ['adviser_tiebreak', 'draw_lots'], true))) {
            abort(422, 'No votes have been cast in this round yet.');
        }

        if ($leaders->count() === 1) {
            $wonBy = $election->current_phase === 'tie_breaker' ? 'tiebreak_runoff' : 'vote';
            $this->recordWinnerAndAdvance($election, $position, $leaders->first(), $maxVotes, $wonBy);

            return response()->json([
                'status' => 'position_finalized',
                'election' => $election->fresh(),
            ]);
        }

        // Tie detected (>= 2 candidates at $maxVotes)
        if ($election->current_phase === 'voting') {
            $this->startTieBreakerRunoff($election, $position, $leaders, $roundNumber + 1);

            return response()->json([
                'status' => 'tie_runoff_started',
                'tied_candidate_ids' => $leaders->pluck('id')->values(),
                'election' => $election->fresh(),
            ]);
        }

        $chosenResolution = $resolution ?? 'runoff';

        if ($chosenResolution === 'adviser_tiebreak') {
            $chosen = $leaders->firstWhere('id', (int) ($validated['winner_candidate_id'] ?? 0));
            abort_unless($chosen !== null, 422, 'Please choose one of the tied candidates to break the tie.');
            $this->recordWinnerAndAdvance($election, $position, $chosen, $maxVotes, 'adviser_tiebreak');

            return response()->json([
                'status' => 'position_finalized',
                'election' => $election->fresh(),
            ]);
        }

        if ($chosenResolution === 'draw_lots') {
            $winner = $leaders->get(random_int(0, $leaders->count() - 1));
            $this->recordWinnerAndAdvance($election, $position, $winner, $maxVotes, 'draw_lots');

            return response()->json([
                'status' => 'position_finalized',
                'election' => $election->fresh(),
            ]);
        }

        $this->startTieBreakerRunoff($election, $position, $leaders, $roundNumber + 1);

        return response()->json([
            'status' => 'tie_runoff_started',
            'tied_candidate_ids' => $leaders->pluck('id')->values(),
            'election' => $election->fresh(),
        ]);
    }

    private function validateNomineeEligibility(Election $election, int $userId, string $position): CampusMembership
    {
        $membership = CampusMembership::query()
            ->where('user_id', $userId)
            ->where('campus_id', $election->campus_id)
            ->where('status', MembershipStatus::Approved->value)
            ->first();

        abort_unless($membership !== null, 422, 'Nominee must be an approved member of this campus.');

        $role = $membership->role instanceof CpfRole
            ? $membership->role
            : CpfRole::tryFrom((string) $membership->role);

        abort_if(
            $role === CpfRole::Adviser,
            422,
            'The Club Adviser presides over the election chamber and cannot be nominated for student officer positions.',
        );

        $isOptedOut = ElectionOptOut::query()
            ->where('election_id', $election->id)
            ->where('user_id', $userId)
            ->exists();

        abort_if(
            $isOptedOut,
            422,
            'This member declined nominations and cannot be nominated unless they re-enable their eligibility.',
        );

        $alreadyWinner = collect($election->position_results ?? [])
            ->contains(fn (array $res) => (int) ($res['winner_user_id'] ?? 0) === $userId);

        abort_if(
            $alreadyWinner,
            422,
            'This member has already been elected to an officer position in this election.',
        );

        $requiredYear = CpfElectionPositions::requiredYear($position);
        if ($requiredYear !== null) {
            $memberYear = CpfElectionPositions::normalizeYearDigit($membership->year_level);
            abort_if(
                $memberYear !== $requiredYear,
                422,
                "Only Year {$requiredYear} members may be nominated for this representative seat.",
            );
        }

        return $membership;
    }

    /**
     * @param  Collection<int, ElectionCandidate>  $tiedLeaders
     */
    private function startTieBreakerRunoff(
        Election $election,
        string $position,
        Collection $tiedLeaders,
        int $nextRound,
    ): void {
        DB::transaction(function () use ($election, $position, $tiedLeaders, $nextRound): void {
            ElectionCandidate::query()
                ->where('election_id', $election->id)
                ->where('position', $position)
                ->update(['is_tie_contender' => false]);

            ElectionCandidate::query()
                ->whereIn('id', $tiedLeaders->pluck('id'))
                ->update(['is_tie_contender' => true]);

            $election->update([
                'current_phase' => 'tie_breaker',
                'round_number' => $nextRound,
            ]);
        });
    }

    private function recordWinnerAndAdvance(
        Election $election,
        string $position,
        ElectionCandidate $winner,
        int $votes,
        string $wonBy,
    ): void {
        $winnerMembership = CampusMembership::query()
            ->where('campus_id', $election->campus_id)
            ->where('user_id', $winner->user_id)
            ->first();

        $results = $election->position_results ?? [];
        $results[$position] = [
            'status' => 'elected',
            'winner_user_id' => $winner->user_id,
            'winner_candidate_id' => $winner->id,
            'winner_name' => $winnerMembership?->full_name_last_first_mi ?? 'CPF Member',
            'course_year_section' => $winnerMembership?->course_year_section ?? '',
            'votes' => $votes,
            'won_by' => $wonBy,
        ];

        $this->advanceElectionPosition($election, $position, $results);
    }

    /**
     * @param  array<string, mixed>  $results
     */
    private function advanceElectionPosition(Election $election, string $currentPosition, array $results): void
    {
        $nextPosition = CpfElectionPositions::nextAfter($currentPosition);

        DB::transaction(function () use ($election, $currentPosition, $nextPosition, $results): void {
            ElectionCandidate::query()
                ->where('election_id', $election->id)
                ->where('position', $currentPosition)
                ->update(['is_tie_contender' => false]);

            if ($nextPosition === null) {
                $election->update([
                    'position_results' => $results,
                    'current_position' => null,
                    'current_phase' => 'completed',
                    'round_number' => 1,
                    'status' => 'closed',
                ]);
            } else {
                $election->update([
                    'position_results' => $results,
                    'current_position' => $nextPosition,
                    'current_phase' => 'nomination',
                    'round_number' => 1,
                ]);
            }
        });
    }

    public function submitPayment(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless($membership !== null, 403, 'A campus membership record is required before submitting dues.');

        $validated = $request->validate([
            'destination_id' => ['required', 'integer', 'exists:payment_destinations,id'],
            'purpose' => ['required', 'string', 'max:140'],
            'amount' => ['required', 'numeric', 'min:1', 'max:50000'],
            'reference_number' => ['required', 'string', 'max:100'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $destination = PaymentDestination::query()->findOrFail($validated['destination_id']);

        abort_unless(
            $destination->campus_id === $membership->campus_id
                && $destination->is_active
                && $destination->approved_by_adviser_id !== null,
            422,
            'Selected payment channel is not approved for your campus.',
        );

        $receiptPath = $request->file('receipt')?->store("receipts/campus_{$membership->campus_id}", 'local');

        $submission = PaymentSubmission::create([
            'campus_id' => $membership->campus_id,
            'user_id' => $user->id,
            'destination_id' => $destination->id,
            'purpose' => trim($validated['purpose']),
            'amount' => $validated['amount'],
            'reference_number' => trim($validated['reference_number']),
            'receipt_hdd_path' => $receiptPath,
            'status' => 'pending',
        ]);

        return response()->json(['status' => 'submitted', 'payment' => $submission], 201);
    }

    public function storePaymentDestination(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership !== null,
            403,
            'Only the Club Adviser can add official payment channels.',
        );

        $validated = $request->validate([
            'channel' => ['required', 'in:GCash,Maya,Cash,Bank'],
            'account_name' => ['required', 'string', 'max:140'],
            'account_number' => ['required', 'string', 'max:80'],
        ]);

        $destination = PaymentDestination::create([
            'campus_id' => $membership->campus_id,
            'channel' => $validated['channel'],
            'account_name' => trim($validated['account_name']),
            'account_number' => trim($validated['account_number']),
            'is_active' => true,
            'approved_by_adviser_id' => $user->id,
        ]);

        return response()->json(['status' => 'created', 'destination' => $destination], 201);
    }

    public function approvePaymentDestination(Request $request, PaymentDestination $destination): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $destination->campus_id,
            403,
            'Only the campus Club Adviser can oversee payment destinations.',
        );

        $destination->update([
            'is_active' => $request->boolean('is_active', true),
            'approved_by_adviser_id' => $user->id,
        ]);

        return response()->json(['status' => 'updated', 'destination' => $destination]);
    }

    public function validatePayment(Request $request, PaymentSubmission $payment): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->canValidatePayments() && $membership?->campus_id === $payment->campus_id,
            403,
            'Only the Treasurer or Club Adviser of this campus can validate payments.',
        );

        $validated = $request->validate([
            'status' => ['required', 'in:validated,rejected'],
            'treasurer_notes' => ['nullable', 'string', 'max:255'],
        ]);

        $payment->update([
            'status' => $validated['status'],
            'validated_by_treasurer_id' => $user->id,
            'validated_at' => now(),
            'treasurer_notes' => $validated['treasurer_notes'] ?? null,
        ]);

        return response()->json(['status' => $payment->status, 'payment' => $payment]);
    }

    public function storeQuote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quote_text' => ['required', 'string', 'max:400'],
            'author_name' => ['nullable', 'string', 'max:120'],
            'mood_tag' => ['nullable', 'string', 'max:40'],
        ]);

        $quote = PeerQuote::create([
            'submitted_by_user_id' => $request->user()?->id,
            'quote_text' => trim($validated['quote_text']),
            'author_name' => trim((string) ($validated['author_name'] ?? 'CPF Peer Circle')),
            'mood_tag' => trim((string) ($validated['mood_tag'] ?? 'Calm')),
            'is_approved' => true,
        ]);

        return response()->json(['status' => 'created', 'quote' => $quote], 201);
    }
}
