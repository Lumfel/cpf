<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campuses', function (Blueprint $table): void {
            if (! Schema::hasColumn('campuses', 'initialized_at')) {
                $table->timestamp('initialized_at')->nullable()->after('mandatory_tour_enabled');
            }
        });

        if (Schema::hasTable('campus_memberships') && Schema::hasColumn('campuses', 'initialized_at')) {
            $initializedCampusIds = DB::table('campus_memberships')
                ->where('role', 'adviser')
                ->where('status', 'approved')
                ->pluck('campus_id')
                ->unique()
                ->all();

            if ($initializedCampusIds !== []) {
                DB::table('campuses')
                    ->whereIn('id', $initializedCampusIds)
                    ->whereNull('initialized_at')
                    ->update(['initialized_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('campuses', function (Blueprint $table): void {
            if (Schema::hasColumn('campuses', 'initialized_at')) {
                $table->dropColumn('initialized_at');
            }
        });
    }
};
