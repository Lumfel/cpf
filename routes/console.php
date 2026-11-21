<?php

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

Schedule::command('cpf:unlock-time-capsules')->daily();
