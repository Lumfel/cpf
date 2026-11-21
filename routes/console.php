<?php

use App\Models\CampusEvent;
use App\Models\TimeCapsule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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

Schedule::command('cpf:unlock-time-capsules')->daily();
Schedule::command('cpf:sync-campus-events')->everyMinute();
