<?php

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Models\AdviserHandover;
use App\Models\CampusEvent;
use App\Models\CampusEventNotification;
use App\Models\CampusMembership;
use App\Models\CounselorInvitation;
use App\Models\Election;
use App\Models\GuidanceReferral;
use App\Models\TimeCapsule;
use Database\Seeders\BlankSystemSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('cpf:unlock-time-capsules', function () {
    $now = now();
    $unlockedCount = TimeCapsule::query()
        ->where('is_opened', false)
        ->where('unlock_at', '<=', $now)
        ->update([
            'is_opened' => true,
            'opened_at' => $now,
        ]);

    $this->info("Unlocked {$unlockedCount} date-locked CPF time capsule(s).");
})->purpose('Unlock encrypted CPF time capsules whose unlock date has arrived');

Artisan::command('cpf:sync-campus-events', function () {
    $now = now();
    $events = CampusEvent::query()
        ->where('status', 'approved')
        ->where(function ($query): void {
            $query->whereNull('activated_at')->orWhereNull('completed_at');
        })
        ->get();

    foreach ($events as $event) {
        $event->syncLifecycleTimestamps($now);
    }

    $this->info("Synchronized lifecycle status for {$events->count()} approved campus event(s).");
})->purpose('Automatically activate scheduled CPF events and notify members when windows open or finish');

Artisan::command('cpf:reset-blank', function () {
    if (app()->isProduction()) {
        $this->error('Refusing to reset database in production.');

        return 1;
    }

    $this->call('migrate:fresh', [
        '--seed' => true,
        '--seeder' => BlankSystemSeeder::class,
        '--force' => true,
    ]);

    $this->info('Database reset to BLANK FIRST-BOOT state. Open / or /login in your browser to enter as the first Club Adviser.');

    return 0;
})->purpose('Wipe database and seed clean blank campuses for first-boot Adviser setup');

Artisan::command('cpf:reset-demo', function () {
    if (app()->isProduction()) {
        $this->error('Refusing to reset database in production.');

        return 1;
    }

    $this->call('migrate:fresh', [
        '--seed' => true,
        '--force' => true,
    ]);

    $this->info('Database reset to full DEMO seeded environment.');

    return 0;
})->purpose('Wipe database and re-seed sample CHMSU demo data');

Schedule::command('cpf:unlock-time-capsules')->daily();
Schedule::command('cpf:sync-campus-events')->everyMinute();

Artisan::command('cpf:purge-expired-election-chat', function () {
    $now = now();
    $expiredIds = Election::query()
        ->where(function ($q) use ($now): void {
            $q->where('status', '!=', 'open')
                ->orWhere('current_phase', 'completed')
                ->orWhere(function ($sub) use ($now): void {
                    $sub->whereNotNull('closes_at')->where('closes_at', '<=', $now);
                });
        })
        ->pluck('id');

    $purged = 0;
    $cache = Election::liveChatCache();
    foreach ($expiredIds as $electionId) {
        $key = Election::liveChatCacheKey((int) $electionId);
        if ($cache->has($key)) {
            $cache->forget($key);
            $purged++;
        }
    }

    $sweptFiles = 0;
    $ephemeralDir = storage_path('framework/cache/ephemeral_chat');
    if (is_dir($ephemeralDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($ephemeralDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $header = @file_get_contents($path, false, null, 0, 10);
            if (is_string($header) && strlen($header) === 10 && ctype_digit($header) && (int) $header <= $now->timestamp) {
                if (@unlink($path)) {
                    $sweptFiles++;
                }
            }
        }
    }

    $this->info("Purged {$purged} expired ephemeral election live-chat cache key(s) and swept {$sweptFiles} expired disk file(s).");
})->purpose('Remove expired ephemeral election live-chat cache entries after closes_at');

Artisan::command('cpf:alert-stale-referrals', function () {
    $cutoff = now()->subHours(24);
    $urgentPriorities = [
        ReferralPriority::Emergency->value,
        ReferralPriority::VeryUrgent->value,
        ReferralPriority::Urgent->value,
    ];

    $stale = GuidanceReferral::query()
        ->where('feedback_status', 'pending')
        ->where(function ($query) use ($urgentPriorities): void {
            $query->whereIn('counselor_confirmed_priority', $urgentPriorities)
                ->orWhere(function ($sub) use ($urgentPriorities): void {
                    $sub->whereNull('counselor_confirmed_priority')
                        ->whereIn('suggested_priority', $urgentPriorities);
                });
        })
        ->whereNull('sla_alerted_at')
        ->where('created_at', '<=', $cutoff)
        ->get();

    $alerted = 0;
    foreach ($stale as $referral) {
        $now = now();
        $claimed = GuidanceReferral::query()
            ->whereKey($referral->id)
            ->whereNull('sla_alerted_at')
            ->update(['sla_alerted_at' => $now]);

        if ($claimed === 0) {
            continue;
        }

        $counselorUserIds = CampusMembership::query()
            ->where('campus_id', $referral->campus_id)
            ->where('status', MembershipStatus::Approved->value)
            ->whereIn('role', [CpfRole::Adviser->value, CpfRole::GuidanceCounselor->value])
            ->pluck('user_id');

        if ($counselorUserIds->isNotEmpty()) {
            $rows = $counselorUserIds->map(fn (int $uid) => [
                'campus_id' => $referral->campus_id,
                'campus_event_id' => null,
                'user_id' => $uid,
                'type' => 'stale_referral_sla_alert',
                'title' => 'Urgent Referral Awaiting Counselor Review (24h+)',
                'message' => "Confidential Referral #{$referral->id} has remained pending for over 24 hours. Please review in Campus Hub.",
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            CampusEventNotification::query()->insert($rows);
        }

        $alerted++;
    }

    $this->info("Sent SLA alerts for {$alerted} stale urgent referral(s).");
})->purpose('Alert campus Guidance Counselors and Club Adviser on urgent referrals pending over 24 hours');

Artisan::command('cpf:expire-stale-invites', function () {
    $now = now();
    [$expiredInvites, $expiredHandovers] = DB::transaction(function () use ($now): array {
        $expiredInvites = CounselorInvitation::query()
            ->whereNull('accepted_at')
            ->where('expires_at', '<=', $now)
            ->delete();

        $staleHandovers = AdviserHandover::query()
            ->where('status', 'pending')
            ->where('expires_at', '<=', $now)
            ->lockForUpdate()
            ->get();

        foreach ($staleHandovers as $handover) {
            $handover->update([
                'status' => 'expired',
                'responded_at' => $now,
            ]);

            CampusMembership::query()
                ->whereKey($handover->to_membership_id)
                ->where('status', MembershipStatus::Pending->value)
                ->where('section', 'Incoming Adviser')
                ->update(['status' => MembershipStatus::Rejected->value]);
        }

        return [$expiredInvites, $staleHandovers->count()];
    });

    $this->info("Expired {$expiredInvites} counselor invitation(s) and {$expiredHandovers} adviser handover(s).");
})->purpose('Expire unaccepted Counselor OTP invitations and Adviser handovers past their 7-day window');

Artisan::command('cpf:remind-upcoming-events', function () {
    $now = now();
    $windowEnd = $now->copy()->addHours(24);

    $events = CampusEvent::query()
        ->where('status', 'approved')
        ->whereNull('activated_at')
        ->whereNull('completed_at')
        ->whereNull('reminder_sent_at')
        ->whereDate('event_date', '>=', $now->toDateString())
        ->whereDate('event_date', '<=', $windowEnd->toDateString())
        ->get();

    $reminded = 0;
    foreach ($events as $event) {
        $claimed = CampusEvent::query()
            ->whereKey($event->id)
            ->whereNull('reminder_sent_at')
            ->update(['reminder_sent_at' => $now]);

        if ($claimed === 0) {
            continue;
        }

        $memberUserIds = CampusMembership::query()
            ->where('campus_id', $event->campus_id)
            ->where('status', MembershipStatus::Approved->value)
            ->where('role', '!=', CpfRole::GuidanceCounselor->value)
            ->pluck('user_id');

        if ($memberUserIds->isNotEmpty()) {
            $eventDateLabel = $event->event_date?->format('Y-m-d') ?? (string) $event->event_date;
            $rows = $memberUserIds->map(fn (int $uid) => [
                'campus_id' => $event->campus_id,
                'campus_event_id' => $event->id,
                'user_id' => $uid,
                'type' => 'event_upcoming_reminder',
                'title' => "Reminder: {$event->title}",
                'message' => "Scheduled on {$eventDateLabel} ({$event->start_time}–{$event->end_time}). Have your Peer QR Pass ready.",
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            CampusEventNotification::query()->insert($rows);
        }

        $reminded++;
    }

    $this->info("Sent 24-hour reminders for {$reminded} upcoming campus event(s).");
})->purpose('Send 24-hour pre-event reminders to approved non-counselor campus members');

Schedule::command('cpf:purge-expired-election-chat')->everyMinute();
Schedule::command('cpf:alert-stale-referrals')->hourly();
Schedule::command('cpf:expire-stale-invites')->daily();
Schedule::command('cpf:remind-upcoming-events')->hourly();
