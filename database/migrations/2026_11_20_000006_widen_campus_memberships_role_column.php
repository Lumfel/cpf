<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campus_memberships') && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `campus_memberships` MODIFY `role` VARCHAR(32) NOT NULL DEFAULT 'member'");
        }
    }

    public function down(): void
    {
        // Non-destructive upgrade migration
    }
};
