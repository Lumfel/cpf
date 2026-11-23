<?php

namespace App\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Models\CampusEvent;
use App\Models\CampusEventNotification;
use App\Models\CampusMembership;
use App\Models\OfficerGcChannel;
use App\Models\PasskeyIssuance;
use App\Models\PeerQuote;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $membership = $user->primaryMembership()
            ->with(['campus:id,code,name,slug,gc_group_name', 'duesConfirmer:id,name'])
            ->first();
        $campusId = $membership?->campus_id;
        $campusCode = $membership?->campus?->code ?? 'CHMSU';
        $roleValue = $user->verifiedRole()?->value ?? 'applicant';

        // Attendance QR is unlocked once the member creates their personal password.
        $qrUnlocked = $user->must_change_password === false;
        $qrSvg = null;
        $qrPayload = null;
        $memberIdCode = null;

        if ($qrUnlocked) {
            $rawPayload = "CPF|campus:{$campusCode}|user:{$user->id}|role:{$roleValue}";
            $signature = substr(hash_hmac('sha256', $rawPayload, (string) config('app.key')), 0, 16);
            $qrPayload = "{$rawPayload}|sig:{$signature}";

            $writer = new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd()));
            $qrSvg = $writer->writeString($qrPayload);
            $memberIdCode = sprintf('CPF-%s-%s-%04d', $campusCode, now()->format('Y'), $user->id);
        }

        $approvedQuotes = PeerQuote::query()
            ->where('is_approved', true)
            ->when($campusId !== null, function ($query) use ($campusId) {
                $query->where(function ($q) use ($campusId) {
                    $q->whereNull('campus_id')->orWhere('campus_id', $campusId);
                });
            })
            ->orderBy('id')
            ->get(['id', 'quote_text', 'author_name', 'mood_tag']);

        $dailyQuote = $approvedQuotes->isNotEmpty()
            ? $approvedQuotes->values()->get(now()->dayOfYear % $approvedQuotes->count())->toArray()
            : [
                'id' => 0,
                'quote_text' => 'Take a breath. Your presence in this community matters more than perfection.',
                'author_name' => 'Circle of Peer Facilitators',
                'mood_tag' => 'Calm',
            ];

        $canConfirmDues = $user->canValidatePayments();
        $canAuditFinances = $user->canAuditFinances();
        $isOfficerOrAdviser = $user->verifiedRole()?->isOfficerOrAdviser() ?? false;
        $duesRoster = [];

        if (($canConfirmDues || $canAuditFinances) && $campusId !== null) {
            $duesRoster = CampusMembership::query()
                ->with(['duesConfirmer:id,name'])
                ->where('campus_id', $campusId)
                ->where('status', MembershipStatus::Approved->value)
                ->alphabetical()
                ->get()
                ->map(fn (CampusMembership $m) => [
                    'id' => $m->id,
                    'full_name_last_first_mi' => $m->full_name_last_first_mi,
                    'course' => $m->course,
                    'year_level' => $m->year_level,
                    'section' => $m->section,
                    'course_year_section' => $m->course_year_section,
                    'role' => $m->role->label(),
                    'facebook_profile_url' => $m->facebook_profile_url,
                    'has_paid_dues' => (bool) $m->has_paid_dues,
                    'dues_confirmed_at' => $m->dues_confirmed_at?->toDayDateTimeString(),
                    'dues_confirmer_name' => $m->duesConfirmer?->name,
                ])
                ->values();
        }

        $officerGcChannel = null;
        $officerGcMessages = collect();

        if ($isOfficerOrAdviser && $campusId !== null) {
            $officerGcChannel = OfficerGcChannel::query()
                ->where('campus_id', $campusId)
                ->orderByDesc('is_active')
                ->orderByDesc('id')
                ->first();

            if ($officerGcChannel) {
                $officerGcMessages = $officerGcChannel->messages()
                    ->with('sender:id,name')
                    ->orderBy('created_at')
                    ->limit(60)
                    ->get()
                    ->map(fn ($msg) => [
                        'id' => $msg->id,
                        'sender_name' => $msg->sender?->name ?? 'Officer',
                        'sender_role' => $msg->sender_role,
                        'message_body' => $msg->message_body,
                        'created_at' => (string) $msg->created_at,
                    ])
                    ->values();
            }
        }

        $activePasskey = $membership
            ? PasskeyIssuance::query()
                ->where('campus_id', $membership->campus_id)
                ->where('target_user_id', $user->id)
                ->where('status', 'issued')
                ->where('is_locked', true)
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first()
            : null;

        $campusEvents = collect();
        if ($campusId !== null) {
            $canProposeEvents = $user->canProposeEvents();
            $campusEvents = CampusEvent::query()
                ->where('campus_id', $campusId)
                ->when(! $canProposeEvents, fn ($query) => $query->whereIn('status', ['approved', 'cancelled']))
                ->orderBy('event_date')
                ->orderBy('start_time')
                ->get()
                ->map(function (CampusEvent $ev): array {
                    $ev->syncLifecycleTimestamps();

                    return [
                        'id' => $ev->id,
                        'title' => $ev->title,
                        'description' => $ev->description,
                        'location' => $ev->location,
                        'event_date' => $ev->event_date->format('Y-m-d'),
                        'event_date_label' => $ev->event_date->format('M d, Y (D)'),
                        'start_time' => $ev->start_time,
                        'end_time' => $ev->end_time,
                        'status' => $ev->status,
                        'effective_status' => $ev->effectiveStatus(),
                        'reflection_enabled' => (bool) $ev->reflection_enabled,
                        'cancellation_reason' => $ev->cancellation_reason,
                    ];
                })
                ->values();
        }

        $eventNotifications = CampusEventNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (CampusEventNotification $n): array => [
                'id' => $n->id,
                'campus_event_id' => $n->campus_event_id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'is_unread' => $n->read_at === null,
                'created_at' => $n->created_at?->diffForHumans(),
            ])
            ->values();

        $unreadEventNotificationsCount = CampusEventNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return Inertia::render('Dashboard', [
            'qrUnlocked' => $qrUnlocked,
            'memberIdCode' => $memberIdCode,
            'qrSvg' => $qrSvg,
            'qrPayload' => $qrPayload,
            'dailyQuote' => $dailyQuote,
            'canConfirmDues' => $canConfirmDues,
            'canAuditFinances' => $canAuditFinances,
            'isOfficerOrAdviser' => $isOfficerOrAdviser,
            'duesRoster' => $duesRoster,
            'campusEvents' => $campusEvents,
            'eventNotifications' => $eventNotifications,
            'unreadEventNotificationsCount' => $unreadEventNotificationsCount,
            'officerGcChannel' => $officerGcChannel ? [
                'id' => $officerGcChannel->id,
                'academic_year' => $officerGcChannel->academic_year,
                'is_active' => (bool) $officerGcChannel->is_active,
            ] : null,
            'officerGcMessages' => $officerGcMessages,
            'myDuesStatus' => $membership ? [
                'has_paid_dues' => (bool) $membership->has_paid_dues,
                'dues_confirmed_at' => $membership->dues_confirmed_at?->toDayDateTimeString(),
                'dues_confirmer_name' => $membership->duesConfirmer?->name,
            ] : null,
            'myPasskey' => $activePasskey ? [
                'enrollment_code' => $activePasskey->encrypted_enrollment_code,
                'is_locked' => (bool) $activePasskey->is_locked,
                'expires_at' => $activePasskey->expires_at?->toDayDateTimeString(),
            ] : null,
        ]);
    }
}
