<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('elections')) {
            Schema::table('elections', function (Blueprint $table) {
                if (! Schema::hasColumn('elections', 'auto_archived_at')) {
                    $table->timestamp('auto_archived_at')->nullable()->after('closes_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('elections') && Schema::hasColumn('elections', 'auto_archived_at')) {
            Schema::table('elections', function (Blueprint $table) {
                $table->dropColumn('auto_archived_at');
            });
        }
    }
};
