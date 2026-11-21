<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `campus_memberships` MODIFY `enrollment_source` VARCHAR(32) NOT NULL DEFAULT 'online_form'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `campus_memberships` MODIFY `enrollment_source` ENUM('online_form', 'officer_added') NOT NULL DEFAULT 'online_form'");
        }
    }
};
