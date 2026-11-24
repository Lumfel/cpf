<?php

namespace App\Actions\Gc;

use App\Models\Campus;
use App\Models\OfficerGcArchive;
use App\Models\OfficerGcChannel;
use App\Models\SchoolYearArchive;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

class ArchiveAndResetOfficerGc
{
    public function handle(User $actor, OfficerGcChannel $channel, string $archiveDir): OfficerGcArchive
    {
        return DB::transaction(function () use ($actor, $channel, $archiveDir): OfficerGcArchive {
            $messages = $channel->messages()->with('sender:id,name')->orderBy('created_at')->get();
            if (! is_dir($archiveDir)) {
                mkdir($archiveDir, 0755, true);
            }

            $zipName = sprintf('cpf_campus_%d_officers_gc_%s.zip', $channel->campus_id, Str::slug($channel->academic_year));
            $zipPath = rtrim($archiveDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$zipName;

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("Unable to create archive ZIP at {$zipPath}");
            }

            $zip->addFromString('transcript.json', $messages->toJson(JSON_PRETTY_PRINT));
            $zip->close();

            $checksum = (string) hash_file('sha256', $zipPath);
            $archivedAt = now();

            $archive = OfficerGcArchive::create([
                'campus_id' => $channel->campus_id,
                'academic_year' => $channel->academic_year,
                'zip_file_name' => $zipName,
                'hdd_storage_path' => $zipPath,
                'sha256_checksum' => $checksum,
                'total_messages' => $messages->count(),
                'archived_by_user_id' => $actor->id,
                'archived_at' => $archivedAt,
            ]);

            SchoolYearArchive::create([
                'campus_id' => $channel->campus_id,
                'academic_year' => $channel->academic_year,
                'archive_type' => 'officer_gc',
                'title' => "Officers Private GC Archive ({$channel->academic_year})",
                'zip_file_name' => $zipName,
                'hdd_storage_path' => $zipPath,
                'sha256_checksum' => $checksum,
                'archived_by_user_id' => $actor->id,
                'archived_at' => $archivedAt,
            ]);

            $channel->messages()->delete();
            $channel->update(['is_active' => false, 'reset_at' => $archivedAt]);

            // Immediately provision and activate a fresh Officers GC channel for the new/next term
            $campusCode = strtolower((string) (Campus::query()->whereKey($channel->campus_id)->value('code') ?? "campus-{$channel->campus_id}"));
            $nextAcademicYear = $this->nextAvailableAcademicYear($channel->campus_id, $channel->academic_year);

            OfficerGcChannel::create([
                'campus_id' => $channel->campus_id,
                'academic_year' => $nextAcademicYear,
                'channel_key' => sprintf('campus.%s.officers-gc.%s', $campusCode, Str::slug($nextAcademicYear)),
                'is_active' => true,
            ]);

            return $archive;
        });
    }

    private function nextAvailableAcademicYear(int $campusId, string $currentAcademicYear): string
    {
        $candidate = $currentAcademicYear;

        if (preg_match('/^(\d{4})-(\d{4})$/', trim($currentAcademicYear), $matches) === 1) {
            $startYear = (int) $matches[1] + 1;
            $endYear = (int) $matches[2] + 1;
            $candidate = "{$startYear}-{$endYear}";

            while (
                OfficerGcChannel::query()
                    ->where('campus_id', $campusId)
                    ->where('academic_year', $candidate)
                    ->exists()
            ) {
                $startYear++;
                $endYear++;
                $candidate = "{$startYear}-{$endYear}";
            }

            return $candidate;
        }

        $base = Str::limit(trim($currentAcademicYear) ?: sprintf('%d-%d', now()->year, now()->year + 1), 15, '');
        $suffix = 2;
        $candidate = "{$base}-T{$suffix}";

        while (
            OfficerGcChannel::query()
                ->where('campus_id', $campusId)
                ->where('academic_year', $candidate)
                ->exists()
        ) {
            $suffix++;
            $candidate = "{$base}-T{$suffix}";
        }

        return $candidate;
    }
}
