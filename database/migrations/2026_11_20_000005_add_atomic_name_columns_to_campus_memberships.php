<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campuses') && ! Schema::hasColumn('campuses', 'current_academic_year')) {
            Schema::table('campuses', function (Blueprint $table) {
                $table->string('current_academic_year', 20)->default('2026-2027')->after('slug');
            });
        }

        if (Schema::hasTable('campus_memberships')) {
            Schema::table('campus_memberships', function (Blueprint $table) {
                if (! Schema::hasColumn('campus_memberships', 'first_name')) {
                    $table->string('first_name', 80)->default('')->after('recorded_email');
                }
                if (! Schema::hasColumn('campus_memberships', 'middle_name')) {
                    $table->string('middle_name', 80)->nullable()->after('first_name');
                }
                if (! Schema::hasColumn('campus_memberships', 'last_name')) {
                    $table->string('last_name', 80)->default('')->after('middle_name');
                }
            });
        }
    }

    public function down(): void
    {
        // Non-destructive upgrade migration
    }
};
