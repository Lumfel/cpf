<?php

namespace App\Http\Controllers;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionOptOut;
use App\Models\ElectionVote;
use App\Models\SchoolYearArchive;
use App\Models\TimeCapsule;
use App\Models\User;
use App\Support\CpfElectionPositions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommunityHubController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $membership = $user->primaryMembership()->with('campus:id,code,name,adviser_name')->first();
        $campusId = $membership?->campus_id ?? Campus::query()->value('id') ?? 1;
        $isClubAdviser = $user->isClubAdviser();

        $approvedMembersRaw = CampusMembership::query()
            ->with('user:id,name,profile_photo_path,updated_at')
            ->where('campus_id', $campusId)
            ->where('status', MembershipStatus::Approved->value)
            ->alphabetical()
            ->get([
                'user_id',
                'full_name_last_first_mi',
                'course',
                'year_level',
                'section',
                'course_year_section',
                'role',
            ]);

        $memberByUserId = $approvedMembersRaw->keyBy('user_id');

        $campusMembers = $approvedMembersRaw->map(function (CampusMembership $m): array {
            $roleEnum = $m->role instanceof CpfRole ? $m->role : CpfRole::tryFrom((string) $m->role);

            return [
                'user_id' => $m->user_id,
                'full_name_last_first_mi' => $m->full_name_last_first_mi,
                'course' => $m->course,
                'year_level' => $m->year_level,
                'year_digit' => CpfElectionPositions::normalizeYearDigit($m->year_level),
                'section' => $m->section,
                'course_year_section' => $m->course_year_section,
                'role' => $roleEnum?->value ?? (string) $m->role,
                'role_label' => $roleEnum?->label() ?? 'Verified Peer Member',
                'avatar_url' => $m->user?->avatar,
            ];
        })->values();

        $adviserMember = $approvedMembersRaw->first(
            fn (CampusMembership $m) => ($m->role instanceof CpfRole ? $m->role : CpfRole::tryFrom((string) $m->role)) === CpfRole::Adviser,
        );

        $adviserDisplay = [
            'name' => $adviserMember?->full_name_last_first_mi
                ?? $membership?->campus?->adviser_name
                ?? 'Campus Club Adviser',
            'title' => 'Club Adviser • Presiding Chair',
            'avatar_url' => $adviserMember?->user?->avatar,
        ];

        $electionsRaw = Election::query()
            ->where('campus_id', $campusId)
            ->latest()
            ->get();

        $candidatesByElection = ElectionCandidate::query()
            ->whereIn('election_id', $electionsRaw->pluck('id'))
            ->get()
            ->groupBy('election_id');

        $optOutsByElection = ElectionOptOut::query()
            ->whereIn('election_id', $electionsRaw->pluck('id'))
            ->get()
            ->groupBy('election_id');

        $candidateUsers = User::query()
            ->whereIn('id', $candidatesByElection->flatten()->pluck('user_id')->unique())
            ->pluck('name', 'id');

        $voteCountsByRound = ElectionVote::query()
            ->whereIn('election_id', $electionsRaw->pluck('id'))
            ->selectRaw('election_id, candidate_id, round_number, COUNT(*) as total')
            ->groupBy('election_id', 'candidate_id', 'round_number')
            ->get()
            ->groupBy('election_id');

        $myVotesByElection = ElectionVote::query()
            ->whereIn('election_id', $electionsRaw->pluck('id'))
            ->where('voter_user_id', $user->id)
            ->get()
            ->groupBy('election_id');

        $elections = $electionsRaw->map(function (Election $election) use (
            $candidatesByElection,
            $optOutsByElection,
            $candidateUsers,
            $memberByUserId,
            $voteCountsByRound,
            $myVotesByElection,
        ) {
            $activeRound = (int) ($election->round_number ?: 1);
            $activePosition = $election->current_position;
            $electionVotes = $voteCountsByRound->get($election->id) ?? collect();

            $candidates = ($candidatesByElection->get($election->id) ?? collect())->map(function (ElectionCandidate $c) use (
                $candidateUsers,
                $memberByUserId,
                $electionVotes,
                $activeRound,
                $activePosition,
            ) {
                $m = $memberByUserId->get($c->user_id);
                $targetRound = ($c->position === $activePosition) ? $activeRound : 1;
                $voteRow = $electionVotes->first(
                    fn ($row) => (int) $row->candidate_id === $c->id && (int) $row->round_number === $targetRound,
                );

                return [
                    'id' => $c->id,
                    'user_id' => $c->user_id,
                    'name' => $m?->full_name_last_first_mi ?? $candidateUsers->get($c->user_id, 'Peer Member'),
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

            $myVotes = ($myVotesByElection->get($election->id) ?? collect())
                ->filter(fn (ElectionVote $v) => $v->position !== $activePosition || (int) ($v->round_number ?: 1) === $activeRound)
                ->pluck('candidate_id', 'position')
                ->all();

            $optedOutUserIds = ($optOutsByElection->get($election->id) ?? collect())
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            return [
                'id' => $election->id,
                'title' => $election->title,
                'academic_year' => $election->academic_year,
                'status' => $election->status,
                'current_position' => $election->current_position,
                'current_phase' => $election->current_phase ?: 'voting',
                'round_number' => $activeRound,
                'position_results' => $election->position_results ?? [],
                'opted_out_user_ids' => $optedOutUserIds,
                'opens_at' => $election->opens_at?->toIso8601String(),
                'closes_at' => $election->closes_at?->toIso8601String(),
                'candidates' => $candidates,
                'my_votes' => $myVotes,
            ];
        })->values();

        $timeCapsules = TimeCapsule::query()
            ->where('user_id', $user->id)
            ->orderBy('unlock_at')
            ->get()
            ->map(function (TimeCapsule $capsule) {
                $readyToOpen = $capsule->is_opened || now()->gte($capsule->unlock_at);

                return [
                    'id' => $capsule->id,
                    'title' => $capsule->title,
                    'letter_style' => $capsule->letter_style ?: 'classic',
                    'unlock_at' => $capsule->unlock_at?->toIso8601String(),
                    'is_opened' => $capsule->is_opened,
                    'ready_to_open' => $readyToOpen,
                    'opened_at' => $capsule->opened_at?->toIso8601String(),
                    'message' => $capsule->is_opened ? $capsule->encrypted_message : null,
                ];
            })
            ->values();

        $schoolYearArchives = SchoolYearArchive::query()
            ->where('campus_id', $campusId)
            ->orderByDesc('archived_at')
            ->get();

        return Inertia::render('community/Index', [
            'campusId' => $campusId,
            'campusName' => $membership?->campus?->name ?? 'CHMSU Campus',
            'isClubAdviser' => $isClubAdviser,
            'isVerifiedMember' => $user->verifiedRole() !== null,
            'myUserId' => $user->id,
            'myRole' => $user->verifiedRole()?->value,
            'adviserDisplay' => $adviserDisplay,
            'positionOrder' => CpfElectionPositions::ordered(),
            'elections' => $elections,
            'campusMembers' => $campusMembers,
            'timeCapsules' => $timeCapsules,
            'schoolYearArchives' => $schoolYearArchives,
        ]);
    }
}
