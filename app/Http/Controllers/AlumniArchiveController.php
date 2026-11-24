<?php

namespace App\Http\Controllers;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusEventAttendance;
use App\Models\CampusMembership;
use App\Models\DigitalCertificate;
use App\Models\MemberAchievement;
use App\Models\MemberAward;
use App\Models\OfficerGcArchive;
use App\Models\OfficerGcChannel;
use App\Models\SchoolYearArchive;
use App\Models\TimeCapsule;
use App\Services\ClubTenureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use ZipArchive;

class AlumniArchiveController extends Controller
{
    public function index(Request $request, ClubTenureService $tenureService): Response
    {
        $user = $request->user();

        abort_unless(
            $user && $user->canAccessAlumniArchive(),
            403,
            'Verified active or former CPF membership is required to access the Former Members & Legacy Archive.',
        );

        $membership = $user->primaryMembership()
            ->with(['campus:id,code,name,current_academic_year', 'tenures'])
            ->firstOrFail();

        $campusId = $membership->campus_id;
        $isAlumni = $user->isAlumni();

        // 1. Personal Credentials (Awards, Achievements, Certificates)
        $myAwards = MemberAward::query()
            ->with(['campus:id,code,name', 'issuer:id,name', 'ledgerEntry'])
            ->where('user_id', $user->id)
            ->orderByDesc('awarded_on')
            ->get()
            ->map(fn (MemberAward $a): array => [
                'id' => $a->id,
                'award_code' => $a->award_code,
                'title' => $a->title,
                'award_category' => $a->award_category,
                'academic_year' => $a->academic_year,
                'awarded_on' => $a->awarded_on?->format('M d, Y'),
                'citation' => $a->citation,
                'issuer_name' => $a->issuer?->name ?? 'Club Adviser',
                'credential_hash' => $a->credential_hash,
                'blockchain_status' => $a->blockchain_status,
                'block_index' => $a->ledgerEntry?->block_index,
            ])
            ->values();

        $myAchievements = MemberAchievement::query()
            ->with(['campus:id,code,name', 'ledgerEntry'])
            ->where('user_id', $user->id)
            ->orderByDesc('achieved_on')
            ->get()
            ->map(fn (MemberAchievement $a): array => [
                'id' => $a->id,
                'achievement_code' => $a->achievement_code,
                'title' => $a->title,
                'category' => $a->category,
                'achieved_on' => $a->achieved_on?->format('M d, Y'),
                'description' => $a->description,
                'credential_hash' => $a->credential_hash,
                'blockchain_status' => $a->blockchain_status,
            ])
            ->values();

        $myCertificates = DigitalCertificate::query()
            ->with(['campus:id,code,name', 'ledgerEntry'])
            ->where('user_id', $user->id)
            ->orderByDesc('issued_on')
            ->get()
            ->map(fn (DigitalCertificate $c): array => [
                'id' => $c->id,
                'certificate_code' => $c->certificate_code,
                'title' => $c->title,
                'event_name' => $c->event_name,
                'issued_on' => $c->issued_on?->format('M d, Y'),
                'credential_hash' => $c->credential_hash,
                'blockchain_status' => $c->blockchain_status,
            ])
            ->values();

        // 2. Campus Hall of Honors (Read-only campus-wide awards/achievements/certificates)
        $campusAwards = MemberAward::query()
            ->with(['user:id,name', 'issuer:id,name'])
            ->where('campus_id', $campusId)
            ->orderByDesc('awarded_on')
            ->limit(100)
            ->get()
            ->map(fn (MemberAward $a): array => [
                'id' => $a->id,
                'award_code' => $a->award_code,
                'recipient_name' => $a->user?->name ?? 'CPF Member',
                'title' => $a->title,
                'award_category' => $a->award_category,
                'academic_year' => $a->academic_year,
                'awarded_on' => $a->awarded_on?->format('M d, Y'),
                'citation' => $a->citation,
                'issuer_name' => $a->issuer?->name ?? 'Club Adviser',
                'credential_hash' => $a->credential_hash,
            ])
            ->values();

        $campusAchievements = MemberAchievement::query()
            ->with('user:id,name')
            ->where('campus_id', $campusId)
            ->orderByDesc('achieved_on')
            ->limit(100)
            ->get()
            ->map(fn (MemberAchievement $a): array => [
                'id' => $a->id,
                'achievement_code' => $a->achievement_code,
                'recipient_name' => $a->user?->name ?? 'CPF Member',
                'title' => $a->title,
                'category' => $a->category,
                'achieved_on' => $a->achieved_on?->format('M d, Y'),
                'description' => $a->description,
            ])
            ->values();

        $campusCertificates = DigitalCertificate::query()
            ->with('user:id,name')
            ->where('campus_id', $campusId)
            ->orderByDesc('issued_on')
            ->limit(100)
            ->get()
            ->map(fn (DigitalCertificate $c): array => [
                'id' => $c->id,
                'certificate_code' => $c->certificate_code,
                'recipient_name' => $c->user?->name ?? 'CPF Member',
                'title' => $c->title,
                'event_name' => $c->event_name,
                'issued_on' => $c->issued_on?->format('M d, Y'),
            ])
            ->values();

        // 3. Time Capsules & Personal Event Attendance Timeline ("Times")
        $timeCapsules = TimeCapsule::query()
            ->where('user_id', $user->id)
            ->orderBy('unlock_at')
            ->get()
            ->map(function (TimeCapsule $capsule): array {
                $readyToOpen = $capsule->is_opened || ($capsule->unlock_at !== null && now()->gte($capsule->unlock_at));

                return [
                    'id' => $capsule->id,
                    'title' => $capsule->title,
                    'letter_style' => $capsule->letter_style ?: 'classic',
                    'unlock_at' => $capsule->unlock_at?->toDayDateTimeString(),
                    'unlock_at_iso' => $capsule->unlock_at?->toIso8601String(),
                    'is_opened' => (bool) $capsule->is_opened,
                    'ready_to_open' => $readyToOpen,
                    'opened_at' => $capsule->opened_at?->toDayDateTimeString(),
                    'message' => $capsule->is_opened ? $capsule->encrypted_message : null,
                ];
            })
            ->values();

        $myAttendedEvents = CampusEventAttendance::query()
            ->with('event:id,campus_id,title,location,event_date,start_time,end_time')
            ->where('user_id', $user->id)
            ->whereNotNull('logged_in_at')
            ->whereHas('event', fn ($q) => $q->where('campus_id', $campusId))
            ->orderByDesc('logged_in_at')
            ->get()
            ->map(fn (CampusEventAttendance $att): array => [
                'id' => $att->id,
                'event_id' => $att->campus_event_id,
                'title' => $att->event?->title ?? 'Campus Activity',
                'location' => $att->event?->location,
                'event_date' => $att->event?->event_date?->format('M d, Y'),
                'time_window' => $att->event ? "{$att->event->start_time}–{$att->event->end_time}" : null,
                'logged_in_at' => $att->logged_in_at?->format('M d, Y h:i A'),
                'logged_out_at' => $att->logged_out_at?->format('M d, Y h:i A'),
            ])
            ->values();

        $myTenure = $tenureService->buildTenureSummary(
            $membership,
            $myAttendedEvents->count(),
            $myAwards->count(),
            $myAchievements->count(),
            $myCertificates->count(),
        );

        // 4. Archived Officers GCs & School Year Archives
        $archivedByUserIds = OfficerGcArchive::query()
            ->where('campus_id', $campusId)
            ->pluck('archived_by_user_id')
            ->filter()
            ->unique()
            ->values();

        $archiverNames = $archivedByUserIds->isNotEmpty()
            ? \App\Models\User::query()->whereIn('id', $archivedByUserIds)->pluck('name', 'id')
            : collect();

        $gcArchives = OfficerGcArchive::query()
            ->where('campus_id', $campusId)
            ->orderByDesc('archived_at')
            ->get()
            ->map(fn (OfficerGcArchive $archive): array => [
                'id' => $archive->id,
                'academic_year' => $archive->academic_year,
                'zip_file_name' => $archive->zip_file_name,
                'sha256_checksum' => $archive->sha256_checksum,
                'total_messages' => (int) $archive->total_messages,
                'archived_by_name' => $archiverNames->get($archive->archived_by_user_id, 'Club Adviser'),
                'archived_at' => $archive->archived_at?->toDayDateTimeString(),
                'has_readable_zip' => is_string($archive->hdd_storage_path) && is_file($archive->hdd_storage_path),
            ])
            ->values();

        $schoolYearArchives = SchoolYearArchive::query()
            ->where('campus_id', $campusId)
            ->orderByDesc('archived_at')
            ->get()
            ->map(fn (SchoolYearArchive $arc): array => [
                'id' => $arc->id,
                'academic_year' => $arc->academic_year,
                'archive_type' => $arc->archive_type,
                'title' => $arc->title,
                'zip_file_name' => $arc->zip_file_name,
                'sha256_checksum' => $arc->sha256_checksum,
                'archived_at' => $arc->archived_at ? \Illuminate\Support\Carbon::parse($arc->archived_at)->toDayDateTimeString() : null,
            ])
            ->values();

        $inactiveGcChannels = OfficerGcChannel::query()
            ->with(['messages' => fn ($q) => $q->with('sender:id,name')->orderBy('created_at')])
            ->where('campus_id', $campusId)
            ->where('is_active', false)
            ->orderByDesc('reset_at')
            ->get()
            ->filter(fn (OfficerGcChannel $ch) => $ch->messages->isNotEmpty())
            ->map(fn (OfficerGcChannel $ch): array => [
                'id' => $ch->id,
                'academic_year' => $ch->academic_year,
                'reset_at' => $ch->reset_at?->toDayDateTimeString(),
                'messages' => $ch->messages->map(fn ($msg): array => [
                    'id' => $msg->id,
                    'sender_name' => $msg->sender?->name ?? 'Officer',
                    'sender_role' => $msg->sender_role,
                    'message_body' => $msg->message_body,
                    'created_at' => (string) $msg->created_at,
                ])->values(),
            ])
            ->values();

        // 5. Dynamic Former Advisers, Officers & Members Directory with Verified Tenure
        $alumniMemberships = CampusMembership::query()
            ->with([
                'user:id,name,profile_photo_path,updated_at',
                'campus:id,code,name,current_academic_year',
                'tenures',
                'alumniTransitionedBy:id,name',
            ])
            ->where('campus_id', $campusId)
            ->where('status', MembershipStatus::Alumni->value)
            ->orderByDesc('alumni_since')
            ->alphabetical()
            ->get();

        $alumniUserIds = $alumniMemberships->pluck('user_id')->unique()->values();

        $attendanceCounts = $alumniUserIds->isNotEmpty()
            ? CampusEventAttendance::query()
                ->whereIn('user_id', $alumniUserIds)
                ->whereNotNull('logged_in_at')
                ->whereHas('event', fn ($q) => $q->where('campus_id', $campusId))
                ->selectRaw('user_id, COUNT(*) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id')
            : collect();

        $awardCounts = $alumniUserIds->isNotEmpty()
            ? MemberAward::query()
                ->where('campus_id', $campusId)
                ->whereIn('user_id', $alumniUserIds)
                ->selectRaw('user_id, COUNT(*) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id')
            : collect();

        $achievementCounts = $alumniUserIds->isNotEmpty()
            ? MemberAchievement::query()
                ->where('campus_id', $campusId)
                ->whereIn('user_id', $alumniUserIds)
                ->selectRaw('user_id, COUNT(*) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id')
            : collect();

        $certificateCounts = $alumniUserIds->isNotEmpty()
            ? DigitalCertificate::query()
                ->where('campus_id', $campusId)
                ->whereIn('user_id', $alumniUserIds)
                ->selectRaw('user_id, COUNT(*) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id')
            : collect();

        $alumniDirectory = $alumniMemberships->map(function (CampusMembership $m) use (
            $tenureService,
            $attendanceCounts,
            $awardCounts,
            $achievementCounts,
            $certificateCounts,
        ): array {
            $highestRole = $m->highestRoleHeld();

            $category = match (true) {
                $highestRole === CpfRole::Adviser => 'adviser',
                $highestRole->isOfficerOrAdviser() => 'officer',
                default => 'member',
            };

            return [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'full_name_last_first_mi' => $m->full_name_last_first_mi,
                'course_year_section' => $m->course_year_section,
                'avatar_url' => $m->user?->avatar,
                'former_role' => $highestRole->value,
                'former_role_label' => $highestRole->formerTitle(),
                'category' => $category,
                'alumni_since' => $m->alumni_since?->format('M d, Y'),
                'alumni_reason' => $m->alumni_reason,
                'alumni_reason_label' => ClubTenureService::reasonLabel($m->alumni_reason),
                'transitioned_by_name' => $m->alumniTransitionedBy?->name,
                'remarks' => $m->remarks,
                'tenure' => $tenureService->buildTenureSummary(
                    $m,
                    (int) $attendanceCounts->get($m->user_id, 0),
                    (int) $awardCounts->get($m->user_id, 0),
                    (int) $achievementCounts->get($m->user_id, 0),
                    (int) $certificateCounts->get($m->user_id, 0),
                ),
            ];
        })->values();

        return Inertia::render('archive/Index', [
            'campusName' => $membership->campus?->name ?? 'CHMSU Campus',
            'isAlumni' => $isAlumni,
            'roleLabel' => $user->verifiedRole()?->label() ?? $user->formerRoleLabel() ?? 'Former Member',
            'myTenure' => $myTenure,
            'myAwards' => $myAwards,
            'myAchievements' => $myAchievements,
            'myCertificates' => $myCertificates,
            'campusAwards' => $campusAwards,
            'campusAchievements' => $campusAchievements,
            'campusCertificates' => $campusCertificates,
            'timeCapsules' => $timeCapsules,
            'myAttendedEvents' => $myAttendedEvents,
            'gcArchives' => $gcArchives,
            'schoolYearArchives' => $schoolYearArchives,
            'inactiveGcChannels' => $inactiveGcChannels,
            'alumniDirectory' => $alumniDirectory,
        ]);
    }

    public function gcTranscript(Request $request, OfficerGcArchive $archive): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user
            && $user->verifiedRole() !== CpfRole::GuidanceCounselor
            && $user->canAccessAlumniArchive()
            && $membership?->campus_id === $archive->campus_id,
            403,
            'Only verified active or former club members and the Club Adviser may view archived Officers GC transcripts.',
        );

        $path = $archive->hdd_storage_path;
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return response()->json([
                'available' => false,
                'archive' => [
                    'id' => $archive->id,
                    'academic_year' => $archive->academic_year,
                    'zip_file_name' => $archive->zip_file_name,
                    'sha256_checksum' => $archive->sha256_checksum,
                    'total_messages' => (int) $archive->total_messages,
                ],
                'messages' => [],
                'notice' => 'Archive ZIP bundle is stored offline on campus HDD storage.',
            ]);
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return response()->json([
                'available' => false,
                'archive' => [
                    'id' => $archive->id,
                    'academic_year' => $archive->academic_year,
                    'zip_file_name' => $archive->zip_file_name,
                    'sha256_checksum' => $archive->sha256_checksum,
                    'total_messages' => (int) $archive->total_messages,
                ],
                'messages' => [],
                'notice' => 'Unable to open archive ZIP bundle.',
            ]);
        }

        $rawJson = $zip->getFromName('transcript.json');
        $zip->close();

        $decoded = is_string($rawJson) ? json_decode($rawJson, true) : null;
        $messages = is_array($decoded)
            ? collect($decoded)->map(fn (array $row): array => [
                'id' => $row['id'] ?? null,
                'sender_name' => $row['sender']['name'] ?? ($row['sender_name'] ?? 'Officer'),
                'sender_role' => $row['sender_role'] ?? 'officer',
                'message_body' => $row['message_body'] ?? '',
                'created_at' => $row['created_at'] ?? null,
            ])->values()->all()
            : [];

        return response()->json([
            'available' => true,
            'archive' => [
                'id' => $archive->id,
                'academic_year' => $archive->academic_year,
                'zip_file_name' => $archive->zip_file_name,
                'sha256_checksum' => $archive->sha256_checksum,
                'total_messages' => (int) $archive->total_messages,
                'archived_at' => $archive->archived_at?->toDayDateTimeString(),
            ],
            'messages' => $messages,
        ]);
    }
}
