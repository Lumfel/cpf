<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('passkey_issuances')) {
            Schema::table('passkey_issuances', function (Blueprint $table): void {
                if (! Schema::hasColumn('passkey_issuances', 'encrypted_enrollment_code')) {
                    $table->text('encrypted_enrollment_code')->nullable()->after('enrollment_code_hash');
                }
                if (! Schema::hasColumn('passkey_issuances', 'is_locked')) {
                    $table->boolean('is_locked')->default(true)->after('status');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('passkey_issuances')) {
            Schema::table('passkey_issuances', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    'encrypted_enrollment_code',
                    'is_locked',
                ], fn (string $col): bool => Schema::hasColumn('passkey_issuances', $col)));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
