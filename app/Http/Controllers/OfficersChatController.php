<?php

namespace App\Http\Controllers;

use App\Models\CounselorGcMessage;
use App\Models\OfficerGcArchive;
use App\Models\OfficerGcChannel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfficersChatController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $membership = $user->primaryMembership()->with('campus:id,code,name')->first();
        $isOfficerOrAdviser = $user->verifiedRole()?->isOfficerOrAdviser() ?? false;
        $isClubAdviser = $user->isClubAdviser();

        abort_if(
            ! $isOfficerOrAdviser || ! $membership,
            403,
            'Officers Chat is restricted to verified CPF Officers and the Club Adviser.',
        );

        $campusId = $membership->campus_id;

        $officerChannel = OfficerGcChannel::query()
            ->where('campus_id', $campusId)
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->first();

        $officerMessages = collect();
        if ($officerChannel) {
            $officerMessages = $officerChannel->messages()
                ->with('sender:id,name')
                ->orderBy('created_at')
                ->limit(100)
                ->get()
                ->map(fn ($msg) => [
                    'id' => $msg->id,
                    'sender_user_id' => $msg->sender_user_id,
                    'sender_name' => $msg->sender?->name ?? 'Officer',
                    'sender' => $msg->sender ? [
                        'id' => $msg->sender->id,
                        'name' => $msg->sender->name,
                    ] : null,
                    'sender_role' => $msg->sender_role,
                    'message_body' => $msg->message_body,
                    'created_at' => (string) $msg->created_at,
                ])
                ->values();
        }

        $counselorGcMessages = collect();
        if ($isClubAdviser) {
            $counselorGcMessages = CounselorGcMessage::query()
                ->with('sender:id,name')
                ->where('campus_id', $campusId)
                ->orderBy('created_at')
                ->limit(100)
                ->get()
                ->map(fn (CounselorGcMessage $msg) => [
                    'id' => $msg->id,
                    'sender_user_id' => $msg->sender_user_id,
                    'sender_name' => $msg->sender?->name ?? 'Guidance Counselor',
                    'sender' => $msg->sender ? [
                        'id' => $msg->sender->id,
                        'name' => $msg->sender->name,
                    ] : null,
                    'sender_role' => $msg->sender_role,
                    'message_body' => $msg->message_body,
                    'created_at' => (string) $msg->created_at,
                ])
                ->values();
        }

        $gcArchives = OfficerGcArchive::query()
            ->where('campus_id', $campusId)
            ->orderByDesc('archived_at')
            ->get();

        $counselorPeers = collect();
        if ($isClubAdviser) {
            $counselorPeers = \App\Models\CampusMembership::query()
                ->with('user:id,name')
                ->where('campus_id', $campusId)
                ->where('status', \App\Enums\MembershipStatus::Approved->value)
                ->whereIn('role', [\App\Enums\CpfRole::Adviser->value, \App\Enums\CpfRole::GuidanceCounselor->value])
                ->where('user_id', '!=', $user->id)
                ->alphabetical()
                ->get()
                ->map(fn ($cm) => [
                    'user_id' => $cm->user_id,
                    'name' => $cm->user?->name ?? $cm->full_name_last_first_mi,
                    'role' => $cm->role->value,
                    'role_label' => $cm->role->label(),
                ])
                ->values();
        }

        return Inertia::render('officers-chat/Index', [
            'counselorPeers' => $counselorPeers,
            'officersGcPaused' => (bool) config('cpf.features.officers_gc_paused', false),
            'campusName' => $membership->campus?->name ?? 'CHMSU Alijis Campus',
            'isClubAdviser' => $isClubAdviser,
            'officerChannel' => $officerChannel,
            'officerMessages' => $officerMessages,
            'counselorGcMessages' => $counselorGcMessages,
            'gcArchives' => $gcArchives,
        ]);
    }
}
