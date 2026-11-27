<?php

namespace App\Http\Controllers;

use App\Actions\Gc\ArchiveAndResetOfficerGc;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusMembership;
use App\Models\DuesAuditLog;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionOptOut;
use App\Models\ElectionVote;
use App\Models\MemberAchievement;
use App\Models\OfficerGcChannel;
use App\Models\PaymentDestination;
use App\Models\PaymentSubmission;
use App\Models\PeerQuote;
use App\Models\TimeCapsule;
use App\Models\User;
use App\Services\ClubTenureService;
use App\Services\ProfanityFilterService;
use App\Support\CpfElectionPositions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CpfV4Controller extends Controller
{
    public function storeAchievement(Request $request): JsonResponse
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
            'An approved campus membership is required to record achievements.',
        );

        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:60'],
            'achieved_on' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_unless(
            (int) $validated['campus_id'] === $membership->campus_id,
            403,
            'Records must belong to your assigned campus chapter.',
        );

        $item = MemberAchievement::create([...$validated, 'user_id' => $user->id]);

        return response()->json(['status' => 'created', 'achievement' => $item], 201);
    }

    public function storeTimeCapsule(Request $request): JsonResponse
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
            'An approved campus membership is required to create a time capsule.',
        );

        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'title' => ['required', 'string', 'max:160'],
            'letter_style' => ['nullable', 'string', Rule::in(['classic', 'letter_in_sunrise', 'joy_blobs', 'vice_dusk', 'twilight_calm'])],
            'message' => ['required', 'string', 'max:4000'],
            'unlock_at' => ['required', 'date', 'after:now'],
        ]);

        abort_unless(
            (int) $validated['campus_id'] === $membership->campus_id,
            403,
            'Records must belong to your assigned campus chapter.',
        );

        $profanityFilter = app(ProfanityFilterService::class);
        $profanityFilter->assertClean((string) $validated['title'], 'title');
        $profanityFilter->assertClean((string) $validated['message'], 'message');

        $capsule = TimeCapsule::create([
            'campus_id' => $membership->campus_id,
            'user_id' => $user->id,
            'title' => $validated['title'],
            'letter_style' => $validated['letter_style'] ?? 'classic',
            'encrypted_message' => $validated['message'],
            'unlock_at' => $validated['unlock_at'],
        ]);

        return response()->json([
            'status' => 'sealed',
            'capsule_id' => $capsule->id,
            'letter_style' => $capsule->letter_style,
        ], 201);
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
                'letter_style' => $capsule->letter_style ?: 'classic',
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

        app(ProfanityFilterService::class)->assertClean((string) $validated['title'], 'title');

        $targetStatus = $validated['status'] ?? 'open';

        $election = DB::transaction(function () use ($membership, $validated, $targetStatus, $user): Election {
            if ($targetStatus === 'open') {
                $hasOpenElection = Election::query()
                    ->where('campus_id', $membership->campus_id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->exists();

                abort_if(
                    $hasOpenElection,
                    422,
                    'Another election session is already open for this campus. Pause or complete it before starting a new open session.',
                );
            }

            return Election::create([
                'campus_id' => $membership->campus_id,
                'academic_year' => trim($validated['academic_year']),
                'title' => trim($validated['title']),
                'status' => $targetStatus,
                'current_position' => 'president',
                'current_phase' => 'nomination',
                'round_number' => 1,
                'position_results' => [],
                'opens_at' => $validated['opens_at'] ?? null,
                'closes_at' => $validated['closes_at'] ?? null,
                'created_by_user_id' => $user->id,
            ]);
        });

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

        if (filled($validated['platform'] ?? null)) {
            app(ProfanityFilterService::class)->assertClean((string) $validated['platform'], 'platform');
        }

        $candidate = DB::transaction(function () use ($election, $validated, $user): ElectionCandidate {
            $lockedElection = Election::query()
                ->whereKey($election->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if(
                $lockedElection->status === 'closed' || $lockedElection->current_phase !== 'nomination',
                422,
                'Candidates can only be added during the nomination phase while the election is not closed.',
            );

            abort_if(
                isset(($lockedElection->position_results ?? [])[$validated['position']]),
                422,
                'This position has already been finalized or skipped.',
            );

            $member = $this->validateNomineeEligibility($lockedElection, (int) $validated['user_id'], $validated['position']);

            return ElectionCandidate::updateOrCreate(
                [
                    'election_id' => $lockedElection->id,
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
        });

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

        abort_if(
            $user->verifiedRole() === CpfRole::GuidanceCounselor,
            403,
            'Guidance Counselors have coordination-only access and do not participate in student club elections.',
        );

        $validated = $request->validate([
            'nominee_user_id' => ['required', 'integer', 'exists:users,id'],
            'platform' => ['nullable', 'string', 'max:1000'],
        ]);

        if (filled($validated['platform'] ?? null)) {
            app(ProfanityFilterService::class)->assertClean((string) $validated['platform'], 'platform');
        }

        $result = DB::transaction(function () use ($election, $validated, $user): array {
            $lockedElection = Election::query()
                ->whereKey($election->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedElection->status === 'open'
                    && $lockedElection->current_phase === 'nomination'
                    && $lockedElection->current_position !== null,
                422,
                'Nominations are not currently open.',
            );

            $position = (string) $lockedElection->current_position;
            $nomineeUserId = (int) $validated['nominee_user_id'];

            $this->validateNomineeEligibility($lockedElection, $nomineeUserId, $position, $user->id);

            $existing = ElectionCandidate::query()
                ->where('election_id', $lockedElection->id)
                ->where('user_id', $nomineeUserId)
                ->where('position', $position)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->nomination_status === 'accepted') {
                return ['http_status' => 200, 'status' => 'already_accepted', 'candidate' => $existing];
            }

            $nominationStatus = ($nomineeUserId === $user->id) ? 'accepted' : 'pending';

            $candidate = ElectionCandidate::updateOrCreate(
                [
                    'election_id' => $lockedElection->id,
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

            return ['http_status' => 201, 'status' => 'nominated', 'candidate' => $candidate];
        });

        return response()->json([
            'status' => $result['status'],
            'candidate' => $result['candidate'],
        ], $result['http_status']);
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

        $updatedCandidate = DB::transaction(function () use ($election, $candidate, $validated, $user): ElectionCandidate {
            $lockedElection = Election::query()
                ->whereKey($election->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedCandidate = ElectionCandidate::query()
                ->whereKey($candidate->id)
                ->where('election_id', $lockedElection->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedElection->status === 'open'
                    && $lockedElection->current_phase === 'nomination'
                    && $lockedCandidate->position === $lockedElection->current_position,
                422,
                'Nominations are not currently open for this position.',
            );

            abort_unless(
                $lockedCandidate->nomination_status === 'pending',
                422,
                'This nomination has already been responded to.',
            );

            if ($validated['decision'] === 'accepted') {
                $this->validateNomineeEligibility($lockedElection, $user->id, $lockedCandidate->position, $user->id);

                $lockedCandidate->update(['nomination_status' => 'accepted']);
            } else {
                $lockedCandidate->update(['nomination_status' => 'declined']);

                ElectionOptOut::query()->firstOrCreate(
                    [
                        'election_id' => $lockedElection->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'opted_out_at' => now(),
                    ],
                );

                ElectionCandidate::query()
                    ->where('election_id', $lockedElection->id)
                    ->where('user_id', $user->id)
                    ->where('nomination_status', 'pending')
                    ->update(['nomination_status' => 'declined']);
            }

            return $lockedCandidate->fresh();
        });

        return response()->json([
            'status' => $validated['decision'],
            'candidate' => $updatedCandidate,
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

        abort_if(
            $user->verifiedRole() === CpfRole::GuidanceCounselor,
            403,
            'Guidance Counselors have coordination-only access and do not participate in student club elections.',
        );

        abort_unless(
            $election->status === 'open' && $election->current_phase !== 'completed',
            422,
            'This election is no longer open for nominations.',
        );

        $alreadyWinner = collect($election->position_results ?? [])
            ->contains(fn (array $res) => ($res['status'] ?? 'elected') === 'elected'
                && (int) ($res['winner_user_id'] ?? 0) === $user->id);

        abort_if(
            $alreadyWinner,
            422,
            'You have already been elected to an officer position in this election.',
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

        $declareVacant = $request->boolean('declare_vacant');

        $result = DB::transaction(function () use ($election, $declareVacant, $user): array {
            $lockedElection = Election::query()
                ->whereKey($election->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedElection->status === 'open'
                    && $lockedElection->current_phase === 'nomination'
                    && $lockedElection->current_position !== null,
                422,
                'No active nomination round is open.',
            );

            $position = (string) $lockedElection->current_position;

            $accepted = ElectionCandidate::query()
                ->where('election_id', $lockedElection->id)
                ->where('position', $position)
                ->where('nomination_status', 'accepted')
                ->lockForUpdate()
                ->get();

            if ($accepted->count() >= 2) {
                ElectionCandidate::query()
                    ->where('election_id', $lockedElection->id)
                    ->where('position', $position)
                    ->where('nomination_status', 'pending')
                    ->update(['nomination_status' => 'declined']);

                $lockedElection->update([
                    'current_phase' => 'voting',
                    'round_number' => 1,
                ]);

                return ['status' => 'voting_opened', 'completed' => false];
            }

            if ($accepted->count() === 1) {
                ElectionCandidate::query()
                    ->where('election_id', $lockedElection->id)
                    ->where('position', $position)
                    ->where('nomination_status', 'pending')
                    ->update(['nomination_status' => 'declined']);

                $completed = $this->recordWinnerAndAdvance($lockedElection, $position, $accepted->first(), 0, 'unopposed', $user);

                return ['status' => 'elected_unopposed', 'completed' => $completed];
            }

            if ($declareVacant) {
                ElectionCandidate::query()
                    ->where('election_id', $lockedElection->id)
                    ->where('position', $position)
                    ->where('nomination_status', 'pending')
                    ->update(['nomination_status' => 'declined']);

                $results = $lockedElection->position_results ?? [];
                $results[$position] = [
                    'status' => 'vacant',
                    'winner_user_id' => null,
                    'winner_name' => null,
                    'course_year_section' => null,
                    'votes' => 0,
                    'won_by' => 'vacant',
                ];

                $completed = $this->advanceElectionPosition($lockedElection, $position, $results, null, $user);

                return ['status' => 'position_vacant', 'completed' => $completed];
            }

            abort(422, 'Need at least 2 accepted candidates to close nominations, or the Club Adviser can declare the seat vacant / skip when 0 candidates accepted.');
        });

        if ($result['completed']) {
            Election::flushLiveChatCache((int) $election->id);
            $this->autoArchiveActiveOfficerGc($election->fresh(), $user);
        }

        return response()->json([
            'status' => $result['status'],
            'election' => $election->fresh(),
        ]);
    }

    public function skipOptionalPosition(Request $request, Election $election): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $election->campus_id,
            403,
            'Only the campus Club Adviser can skip an optional representative position or declare a vacant seat.',
        );

        $result = DB::transaction(function () use ($election, $user): array {
            $lockedElection = Election::query()
                ->whereKey($election->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedElection->status === 'open'
                    && $lockedElection->current_phase === 'nomination'
                    && $lockedElection->current_position !== null,
                422,
                'Skipping is only available during an active nomination round.',
            );

            $position = (string) $lockedElection->current_position;
            $isOptional = CpfElectionPositions::isOptional($position);

            if (! $isOptional) {
                $acceptedCount = ElectionCandidate::query()
                    ->where('election_id', $lockedElection->id)
                    ->where('position', $position)
                    ->where('nomination_status', 'accepted')
                    ->lockForUpdate()
                    ->count();

                abort_if(
                    $acceptedCount > 0,
                    422,
                    'Mandatory officer seats can only be declared vacant when 0 candidates have accepted nominations.',
                );
            }

            ElectionCandidate::query()
                ->where('election_id', $lockedElection->id)
                ->where('position', $position)
                ->where('nomination_status', 'pending')
                ->update(['nomination_status' => 'declined']);

            $seatOutcome = $isOptional ? 'skipped' : 'vacant';
            $results = $lockedElection->position_results ?? [];
            $results[$position] = [
                'status' => $seatOutcome,
                'winner_user_id' => null,
                'winner_name' => null,
                'course_year_section' => null,
                'votes' => 0,
                'won_by' => $seatOutcome,
            ];

            $completed = $this->advanceElectionPosition($lockedElection, $position, $results, null, $user);

            return [
                'status' => $isOptional ? 'position_skipped' : 'position_vacant',
                'completed' => $completed,
            ];
        });

        if ($result['completed']) {
            Election::flushLiveChatCache((int) $election->id);
            $this->autoArchiveActiveOfficerGc($election->fresh(), $user);
        }

        return response()->json([
            'status' => $result['status'],
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

        $updatedElection = DB::transaction(function () use ($election, $validated): Election {
            $lockedElection = Election::query()
                ->whereKey($election->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if(
                $lockedElection->current_phase === 'completed' && in_array($validated['status'], ['open', 'draft'], true),
                422,
                'Completed elections cannot be reopened or reverted to draft. Start a new election session instead.',
            );

            if ($validated['status'] === 'open') {
                $hasOtherOpen = Election::query()
                    ->where('campus_id', $lockedElection->campus_id)
                    ->where('status', 'open')
                    ->whereKeyNot($lockedElection->id)
                    ->lockForUpdate()
                    ->exists();

                abort_if(
                    $hasOtherOpen,
                    422,
                    'Another election session is already open for this campus. Pause or complete it before opening another.',
                );
            }

            $lockedElection->update(['status' => $validated['status']]);

            return $lockedElection->fresh();
        });

        if ($updatedElection->status !== 'open') {
            Election::flushLiveChatCache((int) $updatedElection->id);
        }

        return response()->json(['status' => $updatedElection->status, 'election' => $updatedElection]);
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

        abort_if(
            $user->isClubAdviser() || $user->verifiedRole() === CpfRole::GuidanceCounselor,
            403,
            'Only student CPF members and officers cast floor votes in campus elections.',
        );

        $validated = $request->validate([
            'candidate_id' => ['required', 'integer', 'exists:election_candidates,id'],
            'position' => ['required', 'string', 'max:60'],
        ]);

        $vote = DB::transaction(function () use ($election, $validated, $user): ElectionVote {
            $lockedElection = Election::query()
                ->whereKey($election->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($lockedElection->status === 'open', 422, 'Election is not currently open.');
            abort_if($lockedElection->opens_at !== null && now()->lt($lockedElection->opens_at), 422, 'Election voting has not opened yet.');
            abort_if($lockedElection->closes_at !== null && now()->gt($lockedElection->closes_at), 422, 'Election voting has already closed.');

            $phase = $lockedElection->current_phase ?: 'voting';
            abort_unless(
                in_array($phase, ['voting', 'tie_breaker'], true),
                422,
                'Voting is not currently open for this position.',
            );

            if ($lockedElection->current_position !== null) {
                abort_unless(
                    $validated['position'] === $lockedElection->current_position,
                    422,
                    'Voting is currently open only for the active position.',
                );
            }

            $candidate = ElectionCandidate::query()
                ->where('id', $validated['candidate_id'])
                ->where('election_id', $lockedElection->id)
                ->where('position', $validated['position'])
                ->where('nomination_status', 'accepted')
                ->first();

            abort_unless($candidate !== null, 422, 'Selected candidate is not eligible for this election and position.');

            $candidateAlreadyElected = collect($lockedElection->position_results ?? [])
                ->contains(fn (array $res) => ($res['status'] ?? 'elected') === 'elected'
                    && (int) ($res['winner_user_id'] ?? 0) === (int) $candidate->user_id);

            abort_if(
                $candidateAlreadyElected,
                422,
                'This candidate has already been elected to another position in this election.',
            );

            if ($phase === 'tie_breaker' && $validated['position'] === $lockedElection->current_position) {
                abort_unless(
                    (bool) $candidate->is_tie_contender,
                    422,
                    'Only tied contenders are eligible in this tie-breaker runoff round.',
                );
            }

            $roundNumber = ($validated['position'] === $lockedElection->current_position)
                ? (int) ($lockedElection->round_number ?: 1)
                : 1;

            return ElectionVote::firstOrCreate(
                [
                    'election_id' => $lockedElection->id,
                    'voter_user_id' => $user->id,
                    'position' => $validated['position'],
                    'round_number' => $roundNumber,
                ],
                [
                    'candidate_id' => $validated['candidate_id'],
                    'cast_at' => now(),
                ],
            );
        });

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

        $validated = $request->validate([
            'tie_resolution' => ['nullable', Rule::in(['runoff', 'adviser_tiebreak', 'draw_lots'])],
            'winner_candidate_id' => ['nullable', 'integer', 'exists:election_candidates,id'],
        ]);

        $outcome = DB::transaction(function () use ($election, $validated, $user): array {
            $lockedElection = Election::query()
                ->whereKey($election->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedElection->status === 'open'
                    && in_array($lockedElection->current_phase, ['voting', 'tie_breaker'], true)
                    && $lockedElection->current_position !== null,
                422,
                'Position is not currently in an active voting phase.',
            );

            $position = (string) $lockedElection->current_position;
            $roundNumber = (int) ($lockedElection->round_number ?: 1);

            $eligibleQuery = ElectionCandidate::query()
                ->where('election_id', $lockedElection->id)
                ->where('position', $position)
                ->where('nomination_status', 'accepted');

            if ($lockedElection->current_phase === 'tie_breaker') {
                $eligibleQuery->where('is_tie_contender', true);
            }

            /** @var Collection<int, ElectionCandidate> $candidates */
            $candidates = $eligibleQuery->get();
            abort_if($candidates->isEmpty(), 422, 'No eligible candidates exist for this position.');

            $tallies = ElectionVote::query()
                ->where('election_id', $lockedElection->id)
                ->where('position', $position)
                ->where('round_number', $roundNumber)
                ->selectRaw('candidate_id, COUNT(*) as total')
                ->groupBy('candidate_id')
                ->pluck('total', 'candidate_id');

            $totalVotes = $candidates->sum(fn (ElectionCandidate $c) => (int) $tallies->get($c->id, 0));
            $maxVotes = $candidates->max(fn (ElectionCandidate $c) => (int) $tallies->get($c->id, 0)) ?? 0;
            $leaders = $candidates->filter(fn (ElectionCandidate $c) => (int) $tallies->get($c->id, 0) === $maxVotes)->values();

            $resolution = $validated['tie_resolution'] ?? null;
            $isManualTieBreak = in_array($resolution, ['adviser_tiebreak', 'draw_lots'], true);

            if ($isManualTieBreak) {
                abort_unless(
                    $lockedElection->current_phase === 'tie_breaker',
                    422,
                    'Manual tie-break resolution is only available during an active tie-breaker phase.',
                );

                abort_if(
                    $leaders->count() < 2,
                    422,
                    'This runoff round already has a clear vote leader and is no longer tied. Tally the runoff votes instead.',
                );
            }

            if ($totalVotes === 0 && ! ($lockedElection->current_phase === 'tie_breaker' && $isManualTieBreak)) {
                abort(422, 'No votes have been cast in this round yet.');
            }

            if ($leaders->count() === 1) {
                $wonBy = $lockedElection->current_phase === 'tie_breaker' ? 'tiebreak_runoff' : 'vote';
                $completed = $this->recordWinnerAndAdvance($lockedElection, $position, $leaders->first(), $maxVotes, $wonBy, $user);

                return [
                    'completed' => $completed,
                    'payload' => [
                        'status' => 'position_finalized',
                    ],
                ];
            }

            // Tie detected (>= 2 candidates at $maxVotes)
            if ($lockedElection->current_phase === 'voting') {
                $this->startTieBreakerRunoff($lockedElection, $position, $leaders, $roundNumber + 1);

                return [
                    'completed' => false,
                    'payload' => [
                        'status' => 'tie_runoff_started',
                        'tied_candidate_ids' => $leaders->pluck('id')->values(),
                    ],
                ];
            }

            $chosenResolution = $resolution ?? 'runoff';

            if ($chosenResolution === 'adviser_tiebreak') {
                $chosen = $leaders->firstWhere('id', (int) ($validated['winner_candidate_id'] ?? 0));
                abort_unless($chosen !== null, 422, 'Please choose one of the tied candidates to break the tie.');
                $completed = $this->recordWinnerAndAdvance($lockedElection, $position, $chosen, $maxVotes, 'adviser_tiebreak', $user);

                return [
                    'completed' => $completed,
                    'payload' => [
                        'status' => 'position_finalized',
                    ],
                ];
            }

            if ($chosenResolution === 'draw_lots') {
                $winner = $leaders->get(random_int(0, $leaders->count() - 1));
                $completed = $this->recordWinnerAndAdvance($lockedElection, $position, $winner, $maxVotes, 'draw_lots', $user);

                return [
                    'completed' => $completed,
                    'payload' => [
                        'status' => 'position_finalized',
                    ],
                ];
            }

            $this->startTieBreakerRunoff($lockedElection, $position, $leaders, $roundNumber + 1);

            return [
                'completed' => false,
                'payload' => [
                    'status' => 'tie_runoff_started',
                    'tied_candidate_ids' => $leaders->pluck('id')->values(),
                ],
            ];
        });

        if ($outcome['completed']) {
            Election::flushLiveChatCache((int) $election->id);
            $this->autoArchiveActiveOfficerGc($election->fresh(), $user);
        }

        return response()->json([
            ...$outcome['payload'],
            'election' => $election->fresh(),
        ]);
    }

    private function validateNomineeEligibility(
        Election $election,
        int $userId,
        string $position,
        ?int $actorUserId = null,
    ): CampusMembership {
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
            in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true),
            422,
            'Guidance Counselors and the Club Adviser cannot be nominated for student officer positions.',
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
            ->contains(fn (array $res) => ($res['status'] ?? 'elected') === 'elected'
                && (int) ($res['winner_user_id'] ?? 0) === $userId);

        abort_if(
            $alreadyWinner,
            422,
            ($actorUserId !== null && $actorUserId === $userId)
                ? 'You have already been elected to an officer position in this election and cannot self-nominate for another seat.'
                : 'This member has already been elected to an officer position in this election.',
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
        ?User $actor = null,
    ): bool {
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

        return $this->advanceElectionPosition($election, $position, $results, (int) $winner->user_id, $actor);
    }

    /**
     * Advances the election to the next seat or completes the election and applies
     * atomic officer term turnover inside the active transaction.
     *
     * @param  array<string, mixed>  $results
     * @return bool True if this call completed the final seat of the election.
     */
    private function advanceElectionPosition(
        Election $election,
        string $currentPosition,
        array $results,
        ?int $winnerUserId = null,
        ?User $actor = null,
    ): bool {
        $nextPosition = CpfElectionPositions::nextAfter($currentPosition);

        DB::transaction(function () use ($election, $currentPosition, $nextPosition, $results, $winnerUserId, $actor): void {
            ElectionCandidate::query()
                ->where('election_id', $election->id)
                ->where('position', $currentPosition)
                ->update(['is_tie_contender' => false]);

            if ($winnerUserId !== null) {
                $finalizedPositions = array_keys($results);
                ElectionCandidate::query()
                    ->where('election_id', $election->id)
                    ->where('user_id', $winnerUserId)
                    ->whereNotIn('position', $finalizedPositions)
                    ->whereIn('nomination_status', ['pending', 'accepted'])
                    ->update([
                        'nomination_status' => 'declined',
                        'is_tie_contender' => false,
                    ]);
            }

            if ($nextPosition === null) {
                $election->update([
                    'position_results' => $results,
                    'current_position' => null,
                    'current_phase' => 'completed',
                    'round_number' => 1,
                    'status' => 'closed',
                ]);

                // C1: Apply elected officer roles & transition outgoing non-Adviser officers
                // atomically BEFORE the new term's Officers GC channel is opened.
                app(ClubTenureService::class)->applyElectionTermTurnover($election, $actor);
            } else {
                $election->update([
                    'position_results' => $results,
                    'current_position' => $nextPosition,
                    'current_phase' => 'nomination',
                    'round_number' => 1,
                ]);
            }
        });

        return $nextPosition === null;
    }

    private function autoArchiveActiveOfficerGc(Election $election, ?User $actor = null): void
    {
        DB::transaction(function () use ($election, $actor): void {
            $lockedElection = Election::query()
                ->whereKey($election->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $lockedElection
                || $lockedElection->current_phase !== 'completed'
                || $lockedElection->auto_archived_at !== null
            ) {
                return;
            }

            $activeChannel = OfficerGcChannel::query()
                ->where('campus_id', $lockedElection->campus_id)
                ->where('is_active', true)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $activeChannel) {
                $lockedElection->update(['auto_archived_at' => now()]);

                return;
            }

            $archiver = $actor
                ?? ($lockedElection->created_by_user_id ? User::query()->find($lockedElection->created_by_user_id) : null);

            if (! $archiver) {
                return;
            }

            $archiveDir = (string) config('cpf.archive_path', storage_path('app/archives'));
            app(ArchiveAndResetOfficerGc::class)->handle($archiver, $activeChannel, $archiveDir);

            $lockedElection->update(['auto_archived_at' => now()]);
        });
    }

    public function submitPayment(Request $request): JsonResponse
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
            'An approved campus membership is required before submitting dues.',
        );
        abort_if(
            $user->verifiedRole() === CpfRole::GuidanceCounselor,
            403,
            'Guidance Counselors are coordination-only and are blocked from student club dues.',
        );

        if ($request->filled('campus_id')) {
            abort_unless(
                (int) $request->input('campus_id') === $membership->campus_id,
                403,
                'Payments must belong to your assigned campus chapter.',
            );
        }

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

        return response()->json([
            'status' => 'submitted',
            'payment' => $this->formatPaymentResponse($submission),
        ], 201);
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

        $updatedPayment = DB::transaction(function () use ($payment, $validated, $user): PaymentSubmission {
            $lockedPayment = PaymentSubmission::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedPayment->status === 'pending',
                422,
                'Only pending payment submissions can be validated or rejected.',
            );

            $notes = isset($validated['treasurer_notes']) && filled($validated['treasurer_notes'])
                ? trim((string) $validated['treasurer_notes'])
                : null;

            $lockedPayment->update([
                'status' => $validated['status'],
                'validated_by_treasurer_id' => $user->id,
                'validated_at' => now(),
                'treasurer_notes' => $notes,
            ]);

            if ($validated['status'] === 'validated') {
                $submitterMembership = CampusMembership::query()
                    ->where('campus_id', $lockedPayment->campus_id)
                    ->where('user_id', $lockedPayment->user_id)
                    ->lockForUpdate()
                    ->first();

                if ($submitterMembership !== null) {
                    $oldStatus = (bool) $submitterMembership->has_paid_dues;

                    $submitterMembership->update([
                        'has_paid_dues' => true,
                        'dues_confirmed_by' => $user->id,
                        'dues_confirmed_at' => now(),
                    ]);

                    DuesAuditLog::create([
                        'campus_id' => $lockedPayment->campus_id,
                        'actor_user_id' => $user->id,
                        'target_user_id' => $submitterMembership->user_id,
                        'campus_membership_id' => $submitterMembership->id,
                        'payment_submission_id' => $lockedPayment->id,
                        'old_status' => $oldStatus,
                        'new_status' => true,
                        'reason' => $notes ?: "Validated payment submission #{$lockedPayment->id} ({$lockedPayment->reference_number})",
                        'created_at' => now(),
                    ]);
                }
            }

            return $lockedPayment->fresh();
        });

        return response()->json([
            'status' => $updatedPayment->status,
            'payment' => $this->formatPaymentResponse($updatedPayment),
        ]);
    }

    public function streamReceipt(Request $request, PaymentSubmission $payment): StreamedResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user !== null
            && $user->verifiedRole() !== null
            && $membership !== null
            && $membership->status === MembershipStatus::Approved
            && $membership->campus_id === $payment->campus_id
            && ($payment->user_id === $user->id || $user->canAuditFinances()),
            403,
            'You are not authorized to view this payment receipt.',
        );

        $path = $payment->receipt_hdd_path;
        abort_unless(
            is_string($path) && $path !== '' && Storage::disk('local')->exists($path),
            404,
            'Payment receipt file not found.',
        );

        return Storage::disk('local')->response($path, basename($path), [
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Allowlisted payment fields — never expose internal receipt_hdd_path.
     *
     * @return array<string, mixed>
     */
    private function formatPaymentResponse(PaymentSubmission $payment): array
    {
        return [
            'id' => $payment->id,
            'campus_id' => $payment->campus_id,
            'user_id' => $payment->user_id,
            'destination_id' => $payment->destination_id,
            'purpose' => $payment->purpose,
            'amount' => (float) $payment->amount,
            'reference_number' => $payment->reference_number,
            'has_receipt' => filled($payment->receipt_hdd_path),
            'status' => $payment->status,
            'validated_by_treasurer_id' => $payment->validated_by_treasurer_id,
            'validated_at' => $payment->validated_at ? (string) $payment->validated_at : null,
            'treasurer_notes' => $payment->treasurer_notes,
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    public function storeQuote(Request $request): JsonResponse
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
            'An approved campus membership is required to submit quotes.',
        );

        if ($request->filled('campus_id')) {
            abort_unless(
                (int) $request->input('campus_id') === $membership->campus_id,
                403,
                'Quotes must belong to your assigned campus chapter.',
            );
        }

        $validated = $request->validate([
            'quote_text' => ['required', 'string', 'max:400'],
            'author_name' => ['nullable', 'string', 'max:120'],
            'mood_tag' => ['nullable', 'string', 'max:40'],
        ]);

        $profanityFilter = app(ProfanityFilterService::class);
        $profanityFilter->assertClean((string) $validated['quote_text'], 'quote_text');
        if (filled($validated['author_name'] ?? null)) {
            $profanityFilter->assertClean((string) $validated['author_name'], 'author_name');
        }
        if (filled($validated['mood_tag'] ?? null)) {
            $profanityFilter->assertClean((string) $validated['mood_tag'], 'mood_tag');
        }

        $quote = PeerQuote::create([
            'campus_id' => $membership->campus_id,
            'submitted_by_user_id' => $user->id,
            'quote_text' => trim($validated['quote_text']),
            'author_name' => trim((string) ($validated['author_name'] ?? 'CPF Peer Circle')),
            'mood_tag' => trim((string) ($validated['mood_tag'] ?? 'Calm')),
            'is_approved' => $user->isActiveOfficerOrAdviser(),
        ]);

        return response()->json(['status' => 'created', 'quote' => $quote], 201);
    }
}
