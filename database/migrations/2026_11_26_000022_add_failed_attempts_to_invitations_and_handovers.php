<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counselor_invitations', function (Blueprint $table): void {
            if (! Schema::hasColumn('counselor_invitations', 'failed_attempts')) {
                $table->unsignedTinyInteger('failed_attempts')->default(0)->after('otp_hash');
            }
        });

        Schema::table('adviser_handovers', function (Blueprint $table): void {
            if (! Schema::hasColumn('adviser_handovers', 'failed_attempts')) {
                $table->unsignedTinyInteger('failed_attempts')->default(0)->after('confirmation_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('counselor_invitations', function (Blueprint $table): void {
            if (Schema::hasColumn('counselor_invitations', 'failed_attempts')) {
                $table->dropColumn('failed_attempts');
            }
        });

        Schema::table('adviser_handovers', function (Blueprint $table): void {
            if (Schema::hasColumn('adviser_handovers', 'failed_attempts')) {
                $table->dropColumn('failed_attempts');
            }
        });
    }
};
