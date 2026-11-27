<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasskeyIssuance;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Fortify;

class PasskeyCodeLoginController extends Controller
{
    private const GENERIC_ERROR_MESSAGE = 'Invalid email or passkey code.';

    private const DUMMY_BCRYPT_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'passkey_code' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $providedCode = $validated['code'] ?? null;
        $providedRecoveryCode = $validated['recovery_code'] ?? null;
        $hasInlineTwoFactor = filled($providedCode) || filled($providedRecoveryCode);

        [$user, $issuance, $deferredToChallenge] = self::verifyAndMaybeClaimPasskey(
            (string) $validated['email'],
            (string) $validated['passkey_code'],
            deferClaimOnMissingTwoFactor: ! $hasInlineTwoFactor,
            code: $providedCode,
            recoveryCode: $providedRecoveryCode,
            jsonTwoFactorFailure: false,
        );

        if ($deferredToChallenge) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => true,
                'login.passkey_issuance_id' => $issuance->id,
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

    public static function claimPasskeyCode(
        string $email,
        string $passkeyCode,
        ?string $code = null,
        ?string $recoveryCode = null,
        bool $jsonTwoFactorFailure = true,
    ): User {
        [$user] = self::verifyAndMaybeClaimPasskey(
            $email,
            $passkeyCode,
            deferClaimOnMissingTwoFactor: false,
            code: $code,
            recoveryCode: $recoveryCode,
            jsonTwoFactorFailure: $jsonTwoFactorFailure,
        );

        return $user;
    }

    /**
     * @return array{0: User, 1: PasskeyIssuance}
     */
    public static function claimPasskeyCodeWithIssuance(
        string $email,
        string $passkeyCode,
        ?string $code = null,
        ?string $recoveryCode = null,
    ): array {
        [$user, $issuance] = self::verifyAndMaybeClaimPasskey(
            $email,
            $passkeyCode,
            deferClaimOnMissingTwoFactor: false,
            code: $code,
            recoveryCode: $recoveryCode,
            jsonTwoFactorFailure: false,
        );

        return [$user, $issuance];
    }

    public static function finalizeDeferredPasskeyClaim(User $user, int $issuanceId): void
    {
        DB::transaction(function () use ($user, $issuanceId): void {
            $issuance = PasskeyIssuance::query()
                ->whereKey($issuanceId)
                ->where('target_user_id', $user->id)
                ->where('status', 'issued')
                ->where('is_locked', true)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $issuance) {
                throw ValidationException::withMessages([
                    'code' => [self::GENERIC_ERROR_MESSAGE],
                ]);
            }

            $issuance->forceFill([
                'status' => 'claimed',
                'claimed_at' => now(),
            ])->save();
        });
    }

    /**
     * @return array{valid: bool, missing: bool}
     */
    public static function verifyTwoFactorChallenge(
        User $user,
        ?string $code,
        ?string $recoveryCode,
    ): array {
        $trimmedCode = trim((string) ($code ?? ''));
        $trimmedRecovery = trim((string) ($recoveryCode ?? ''));

        if ($trimmedCode === '' && $trimmedRecovery === '') {
            return ['valid' => false, 'missing' => true];
        }

        if ($trimmedCode !== '' && ! empty($user->two_factor_secret)) {
            $valid = (bool) app(TwoFactorAuthenticationProvider::class)->verify(
                Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
                $trimmedCode,
            );

            if ($valid) {
                return ['valid' => true, 'missing' => false];
            }
        }

        if ($trimmedRecovery !== '' && ! empty($user->two_factor_recovery_codes)) {
            $matched = collect($user->recoveryCodes())->first(
                fn (string $stored): bool => hash_equals($stored, $trimmedRecovery),
            );

            if ($matched !== null) {
                $user->replaceRecoveryCode($matched);

                return ['valid' => true, 'missing' => false];
            }
        }

        return ['valid' => false, 'missing' => false];
    }

    /**
     * @return array{0: User, 1: PasskeyIssuance, 2: bool}
     */
    private static function verifyAndMaybeClaimPasskey(
        string $email,
        string $passkeyCode,
        bool $deferClaimOnMissingTwoFactor,
        ?string $code,
        ?string $recoveryCode,
        bool $jsonTwoFactorFailure,
    ): array {
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

        [$claimedIssuance, $deferred] = DB::transaction(function () use (
            $user,
            $formattedCode,
            $deferClaimOnMissingTwoFactor,
            $code,
            $recoveryCode,
            $jsonTwoFactorFailure,
        ): array {
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

            $needsTwoFactor = $user->hasEnabledTwoFactorAuthentication()
                || (! empty($user->two_factor_secret) && (bool) $issuance->require_2fa);

            if ($needsTwoFactor) {
                $twoFactor = self::verifyTwoFactorChallenge($user, $code, $recoveryCode);

                if (! $twoFactor['valid']) {
                    if ($deferClaimOnMissingTwoFactor && $twoFactor['missing']) {
                        if ($issuance->issued_by_user_id !== $user->id && ! $user->must_change_password) {
                            $user->forceFill([
                                'must_change_password' => true,
                                'temporary_password_issued_at' => now(),
                            ])->save();
                        }

                        return [$issuance, true];
                    }

                    if ($jsonTwoFactorFailure) {
                        throw new HttpResponseException(response()->json([
                            'message' => $twoFactor['missing']
                                ? 'Two-factor authentication code is required.'
                                : 'The provided two-factor authentication code was invalid.',
                            'two_factor_required' => true,
                            'errors' => [
                                'code' => [
                                    $twoFactor['missing']
                                        ? 'A valid two-factor authentication code or recovery code is required.'
                                        : 'The provided two-factor authentication code was invalid.',
                                ],
                            ],
                        ], 422));
                    }

                    throw ValidationException::withMessages([
                        'code' => [
                            $twoFactor['missing']
                                ? 'A valid two-factor authentication code or recovery code is required.'
                                : 'The provided two-factor authentication code was invalid.',
                        ],
                    ]);
                }
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

            return [$issuance, false];
        });

        return [$user->fresh(), $claimedIssuance, $deferred];
    }
}
