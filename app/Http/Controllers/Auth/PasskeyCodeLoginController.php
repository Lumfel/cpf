<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasskeyIssuance;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;

class PasskeyCodeLoginController extends Controller
{
    private const GENERIC_ERROR_MESSAGE = 'Invalid email or passkey code.';

    private const DUMMY_BCRYPT_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'passkey_code' => ['required', 'string'],
        ]);

        [$user, $issuance] = self::claimPasskeyCodeWithIssuance(
            (string) $validated['email'],
            (string) $validated['passkey_code'],
        );

        if (
            ! empty($user->two_factor_secret)
            && ($user->two_factor_confirmed_at !== null || (bool) $issuance->require_2fa)
        ) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => true,
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        if ($user->requiresPasswordChange()) {
            return redirect()->route('password.force-change.edit');
        }

        return redirect()->intended(route('dashboard'));
    }

    public static function claimPasskeyCode(string $email, string $passkeyCode): User
    {
        [$user] = self::claimPasskeyCodeWithIssuance($email, $passkeyCode);

        return $user;
    }

    /**
     * @return array{0: User, 1: PasskeyIssuance}
     */
    public static function claimPasskeyCodeWithIssuance(string $email, string $passkeyCode): array
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $passkeyCode) ?? '');

        if (strlen($clean) !== 12) {
            Hash::check($clean, self::DUMMY_BCRYPT_HASH);

            throw ValidationException::withMessages([
                'passkey_code' => self::GENERIC_ERROR_MESSAGE,
            ]);
        }

        $formattedCode = implode('-', str_split($clean, 4));
        $user = User::query()->where('email', strtolower(trim($email)))->first();

        if (! $user) {
            Hash::check($formattedCode, self::DUMMY_BCRYPT_HASH);

            throw ValidationException::withMessages([
                'passkey_code' => self::GENERIC_ERROR_MESSAGE,
            ]);
        }

        $claimedIssuance = DB::transaction(function () use ($user, $formattedCode): PasskeyIssuance {
            $issuance = PasskeyIssuance::query()
                ->where('target_user_id', $user->id)
                ->where('status', 'issued')
                ->where('is_locked', true)
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $hashToCheck = $issuance?->enrollment_code_hash ?? self::DUMMY_BCRYPT_HASH;
            $matches = Hash::check($formattedCode, $hashToCheck);

            if (! $issuance || ! $matches) {
                throw ValidationException::withMessages([
                    'passkey_code' => self::GENERIC_ERROR_MESSAGE,
                ]);
            }

            $issuance->forceFill([
                'status' => 'claimed',
                'claimed_at' => now(),
            ])->save();

            // Officer-issued passkeys require personal password rotation before full portal/API access.
            if ($issuance->issued_by_user_id !== $user->id && ! $user->must_change_password) {
                $user->forceFill([
                    'must_change_password' => true,
                    'temporary_password_issued_at' => now(),
                ])->save();
            }

            return $issuance;
        });

        return [$user->fresh(), $claimedIssuance];
    }
}
