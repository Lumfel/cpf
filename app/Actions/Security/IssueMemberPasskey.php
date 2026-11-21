<?php

namespace App\Actions\Security;

use App\Models\CampusMembership;
use App\Models\PasskeyIssuance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class IssueMemberPasskey
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generateCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $groups = [];

        for ($g = 0; $g < 3; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= self::ALPHABET[random_int(0, $max)];
            }
            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }

    /**
     * Issue or return the member's locked passkey code.
     * Once generated, the code stays locked so accidental clicks cannot overwrite it
     * unless $forceRegenerate is explicitly true.
     *
     * @return array{issuance: PasskeyIssuance, enrollment_code: string, is_locked: bool, regenerated: bool}
     */
    public function handle(
        User $issuer,
        CampusMembership $target,
        bool $require2fa = true,
        bool $forceRegenerate = false,
    ): array {
        if (! $forceRegenerate) {
            $existing = PasskeyIssuance::query()
                ->where('campus_id', $target->campus_id)
                ->where('target_user_id', $target->user_id)
                ->where('status', 'issued')
                ->where('is_locked', true)
                ->where('expires_at', '>', now())
                ->whereNotNull('encrypted_enrollment_code')
                ->latest('id')
                ->first();

            if ($existing && is_string($existing->encrypted_enrollment_code) && $existing->encrypted_enrollment_code !== '') {
                return [
                    'issuance' => $existing,
                    'enrollment_code' => $existing->encrypted_enrollment_code,
                    'is_locked' => true,
                    'regenerated' => false,
                ];
            }
        }

        return DB::transaction(function () use ($issuer, $target, $require2fa, $forceRegenerate): array {
            PasskeyIssuance::query()
                ->where('campus_id', $target->campus_id)
                ->where('target_user_id', $target->user_id)
                ->where('status', 'issued')
                ->update([
                    'status' => 'revoked',
                    'is_locked' => false,
                ]);

            $plainCode = $this->generateCode();

            $issuance = PasskeyIssuance::create([
                'campus_id' => $target->campus_id,
                'target_user_id' => $target->user_id,
                'issued_by_user_id' => $issuer->id,
                'enrollment_code_hash' => Hash::make($plainCode),
                'encrypted_enrollment_code' => $plainCode,
                'require_2fa' => $require2fa,
                'status' => 'issued',
                'is_locked' => true,
                'expires_at' => now()->addDays(30),
            ]);

            return [
                'issuance' => $issuance,
                'enrollment_code' => $plainCode,
                'is_locked' => true,
                'regenerated' => $forceRegenerate,
            ];
        });
    }
}
