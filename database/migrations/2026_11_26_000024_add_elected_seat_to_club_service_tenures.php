<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_service_tenures', function (Blueprint $table): void {
            if (! Schema::hasColumn('club_service_tenures', 'elected_seat')) {
                $table->string('elected_seat', 40)->nullable()->after('role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('club_service_tenures', function (Blueprint $table): void {
            if (Schema::hasColumn('club_service_tenures', 'elected_seat')) {
                $table->dropColumn('elected_seat');
            }
        });
    }
};
