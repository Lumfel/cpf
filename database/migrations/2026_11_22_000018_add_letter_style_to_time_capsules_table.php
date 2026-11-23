<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_capsules', function (Blueprint $table): void {
            $table->string('letter_style', 40)->default('classic')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('time_capsules', function (Blueprint $table): void {
            $table->dropColumn('letter_style');
        });
    }
};
