<?php

namespace App\Actions\Gc;

use App\Models\Campus;
use App\Models\CounselorGcMessage;
use App\Models\SchoolYearArchive;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

class ArchiveCounselorGc
{
    /**
     * Export the campus Counselors Private GC thread into a SHA-256 verified ZIP
     * archive, index it in school_year_archives, and clear the live thread.
     *
     * @return array{archive: SchoolYearArchive, total_messages: int}
     */
    public function handle(User $actor, int $campusId, string $archiveDir): array
    {
        return DB::transaction(function () use ($actor, $campusId, $archiveDir): array {
            $campus = Campus::query()->find($campusId);
            $academicYear = $campus?->current_academic_year ?: sprintf('%d-%d', now()->year, now()->year + 1);

            $messages = CounselorGcMessage::query()
                ->with('sender:id,name')
                ->where('campus_id', $campusId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if (! is_dir($archiveDir)) {
                mkdir($archiveDir, 0755, true);
            }

            $zipName = sprintf(
                'cpf_campus_%d_counselors_gc_%s_%s.zip',
                $campusId,
                Str::slug($academicYear),
                now()->format('Ymd_His'),
            );
            $zipPath = rtrim($archiveDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$zipName;

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Unable to create Counselors GC archive ZIP at {$zipPath}");
            }

            $zip->addFromString('transcript.json', $messages->toJson(JSON_PRETTY_PRINT));
            $zip->close();

            $checksum = (string) hash_file('sha256', $zipPath);
            $archivedAt = now();
            $totalMessages = $messages->count();

            $archive = SchoolYearArchive::create([
                'campus_id' => $campusId,
                'academic_year' => $academicYear,
                'archive_type' => 'counselor_gc',
                'title' => "Counselors Private GC Archive ({$academicYear})",
                'zip_file_name' => $zipName,
                'hdd_storage_path' => $zipPath,
                'sha256_checksum' => $checksum,
                'archived_by_user_id' => $actor->id,
                'archived_at' => $archivedAt,
            ]);

            CounselorGcMessage::query()
                ->where('campus_id', $campusId)
                ->delete();

            return [
                'archive' => $archive,
                'total_messages' => $totalMessages,
            ];
        });
    }
}
