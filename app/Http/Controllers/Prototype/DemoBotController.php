<?php

namespace App\Http\Controllers\Prototype;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\CounselorGcMessage;
use App\Models\OfficerGcChannel;
use App\Models\OfficerGcMessage;
use App\Services\ProfanityFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dev-only Bot Simulator for live demonstrations of GC messaging + profanity filter,
 * parliamentary elections, and deferred awarding previews.
 * Strictly unreachable when app()->isProduction() or cpf.prototype.enabled is false.
 */
class DemoBotController extends Controller
{
    private const DEMO_TAG = '[DEMO-BOT]';

    private function guardPrototype(): void
    {
        abort_if(
            app()->isProduction() || ! config('cpf.prototype.enabled'),
            404,
            'Prototype demo bots are disabled in production.',
        );
    }

    public function index(Request $request): Response
    {
        $this->guardPrototype();

        $campus = $request->user()?->primaryMembership?->campus
            ?? Campus::query()->where('is_active', true)->first();

        $demoLog = Cache::get($this->cacheKey($campus?->id ?? 0), [
            'gc' => [],
            'election' => null,
            'awarding' => null,
        ]);

        return Inertia::render('prototype/DemoBots', [
            'campusName' => $campus?->name ?? 'CHMSU Alijis Campus',
            'demoLog' => $demoLog,
        ]);
    }

    public function runGcBot(Request $request, ProfanityFilterService $profanityFilter): JsonResponse
    {
        $this->guardPrototype();

        $user = $request->user();
        $campusId = $user?->primaryMembership?->campus_id
            ?? Campus::query()->where('is_active', true)->value('id');

        abort_if(! $campusId, 422, 'No active campus available for GC bot simulation.');

        $script = [
            ['role' => 'adviser', 'label' => 'Bot Adviser', 'text' => self::DEMO_TAG.' Good morning team! Please finalize the CHMSU Alijis General Assembly QR checklist.'],
            ['role' => 'president', 'label' => 'Bot President', 'text' => self::DEMO_TAG.' Copy Doc! Sergeant-at-Arms and Secretary are testing the 2-stage QR scanner now.'],
            ['role' => 'member', 'label' => 'Bot Troll Test', 'text' => self::DEMO_TAG.' Testing multilingual filter: yawa gago putangina!'],
            ['role' => 'guidance_counselor', 'label' => 'Bot Counselor', 'text' => self::DEMO_TAG.' Reminder: Testing Room 09:00-11:00 is reserved for confidential referrals.'],
        ];

        $results = [];
        $channel = OfficerGcChannel::query()
            ->where('campus_id', $campusId)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        foreach ($script as $step) {
            $blocked = $profanityFilter->containsProfanity($step['text']);
            if ($blocked) {
                $results[] = [
                    'actor' => $step['label'],
                    'text' => $step['text'],
                    'status' => 'blocked_by_profanity_filter',
                    'note' => 'Blocked automatically by 5-language ProfanityFilterService (EN/ES/HIL/CEB/TL).',
                ];
                continue;
            }

            if ($step['role'] === 'guidance_counselor' && $user) {
                CounselorGcMessage::create([
                    'campus_id' => $campusId,
                    'sender_user_id' => $user->id,
                    'sender_role' => CpfRole::GuidanceCounselor->value,
                    'message_body' => $step['text'],
                ]);
            } elseif ($channel && $user) {
                OfficerGcMessage::create([
                    'channel_id' => $channel->id,
                    'sender_user_id' => $user->id,
                    'sender_role' => $step['role'],
                    'message_body' => $step['text'],
                ]);
            }

            $results[] = [
                'actor' => $step['label'],
                'text' => $step['text'],
                'status' => 'delivered',
                'note' => $step['role'] === 'guidance_counselor'
                    ? 'Delivered to Main Counselors GC'
                    : 'Delivered to Active Officers GC',
            ];
        }

        $state = Cache::get($this->cacheKey($campusId), []);
        $state['gc'] = $results;
        Cache::put($this->cacheKey($campusId), $state, now()->addHours(4));

        return response()->json(['status' => 'ok', 'results' => $results]);
    }

    public function runElectionBot(Request $request): JsonResponse
    {
        $this->guardPrototype();

        $campusId = $request->user()?->primaryMembership?->campus_id
            ?? Campus::query()->where('is_active', true)->value('id')
            ?? 0;

        $simulation = [
            'title' => self::DEMO_TAG.' Simulated General Assembly Election (16 Bot Voters)',
            'rounds' => [
                [
                    'position' => 'President',
                    'round' => 1,
                    'tallies' => ['Bot Candidate A' => 8, 'Bot Candidate B' => 8],
                    'outcome' => 'Tie detected (8–8) → Automatic Runoff Triggered',
                ],
                [
                    'position' => 'President (Runoff)',
                    'round' => 2,
                    'tallies' => ['Bot Candidate A' => 10, 'Bot Candidate B' => 6],
                    'outcome' => 'Elected: Bot Candidate A → Auto Role Promotion & Winner Lock Active',
                ],
                [
                    'position' => 'Vice President',
                    'round' => 1,
                    'tallies' => ['Bot Candidate B' => 11, 'Bot Candidate C' => 5],
                    'outcome' => 'Elected: Bot Candidate B (Candidate A locked out as prior seat winner)',
                ],
            ],
            'ephemeral_chat_purged' => true,
            'simulated_at' => now()->toDateTimeString(),
        ];

        $state = Cache::get($this->cacheKey($campusId), []);
        $state['election'] = $simulation;
        Cache::put($this->cacheKey($campusId), $state, now()->addHours(4));

        return response()->json(['status' => 'ok', 'simulation' => $simulation]);
    }

    public function runAwardingBot(Request $request): JsonResponse
    {
        $this->guardPrototype();

        $campusId = $request->user()?->primaryMembership?->campus_id
            ?? Campus::query()->where('is_active', true)->value('id')
            ?? 0;

        $preview = [
            'badge' => 'PROTOTYPE / DEFERRED PREVIEW',
            'notice' => 'Awards & Digital Certificates are intentionally deferred from the active CHMSU Alijis pilot and shown strictly for prototype walkthroughs.',
            'sample_awards' => [
                [
                    'recipient' => 'Demo Peer Facilitator',
                    'award' => 'Peer Support Excellence Badge',
                    'verification_code' => 'PROTO-CPF-'.strtoupper(substr(md5((string) now()), 0, 8)),
                    'status' => 'Simulated Prototype Preview Only',
                ],
                [
                    'recipient' => 'Demo Outgoing Secretary',
                    'award' => 'Certificate of Chapter Service (AY 2026–2027)',
                    'verification_code' => 'PROTO-CPF-'.strtoupper(substr(md5((string) (now()->timestamp + 1)), 0, 8)),
                    'status' => 'Simulated Prototype Preview Only',
                ],
            ],
            'simulated_at' => now()->toDateTimeString(),
        ];

        $state = Cache::get($this->cacheKey($campusId), []);
        $state['awarding'] = $preview;
        Cache::put($this->cacheKey($campusId), $state, now()->addHours(4));

        return response()->json(['status' => 'ok', 'preview' => $preview]);
    }

    public function reset(Request $request): JsonResponse
    {
        $this->guardPrototype();

        $campusId = $request->user()?->primaryMembership?->campus_id
            ?? Campus::query()->where('is_active', true)->value('id')
            ?? 0;

        $deletedOfficer = OfficerGcMessage::query()
            ->where('message_body', 'like', self::DEMO_TAG.'%')
            ->delete();
        $deletedCounselor = CounselorGcMessage::query()
            ->where('message_body', 'like', self::DEMO_TAG.'%')
            ->delete();

        Cache::forget($this->cacheKey($campusId));

        return response()->json([
            'status' => 'reset',
            'deleted_messages' => $deletedOfficer + $deletedCounselor,
        ]);
    }

    private function cacheKey(int $campusId): string
    {
        return "cpf:prototype:demo_bots:{$campusId}";
    }
}
