<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'must_change_password')) {
                    $table->boolean('must_change_password')->default(false)->after('password');
                }
                if (! Schema::hasColumn('users', 'temporary_password_issued_at')) {
                    $table->timestamp('temporary_password_issued_at')->nullable()->after('must_change_password');
                }
            });
        }

        if (Schema::hasTable('campus_memberships')) {
            Schema::table('campus_memberships', function (Blueprint $table): void {
                if (! Schema::hasColumn('campus_memberships', 'course')) {
                    $table->string('course', 60)->nullable()->after('full_name_last_first_mi');
                }
                if (! Schema::hasColumn('campus_memberships', 'year_level')) {
                    $table->string('year_level', 20)->nullable()->after('course');
                }
                if (! Schema::hasColumn('campus_memberships', 'section')) {
                    $table->string('section', 20)->nullable()->after('year_level');
                }
                if (! Schema::hasColumn('campus_memberships', 'facebook_profile_url')) {
                    $table->string('facebook_profile_url', 500)->nullable()->after('active_fb_account');
                }
                if (! Schema::hasColumn('campus_memberships', 'has_paid_dues')) {
                    $table->boolean('has_paid_dues')->default(false)->after('gc_confirmed_at');
                }
                if (! Schema::hasColumn('campus_memberships', 'dues_confirmed_by')) {
                    $table->foreignId('dues_confirmed_by')->nullable()->after('has_paid_dues')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('campus_memberships', 'dues_confirmed_at')) {
                    $table->timestamp('dues_confirmed_at')->nullable()->after('dues_confirmed_by');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('campus_memberships')) {
            Schema::table('campus_memberships', function (Blueprint $table): void {
                if (Schema::hasColumn('campus_memberships', 'dues_confirmed_by')) {
                    $table->dropConstrainedForeignId('dues_confirmed_by');
                }

                $columns = array_values(array_filter([
                    'course',
                    'year_level',
                    'section',
                    'facebook_profile_url',
                    'has_paid_dues',
                    'dues_confirmed_at',
                ], fn (string $column): bool => Schema::hasColumn('campus_memberships', $column)));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    'must_change_password',
                    'temporary_password_issued_at',
                ], fn (string $column): bool => Schema::hasColumn('users', $column)));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
