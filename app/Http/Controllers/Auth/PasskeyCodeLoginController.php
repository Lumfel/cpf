<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasskeyIssuance;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasskeyCodeLoginController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'passkey_code' => ['required', 'string'],
        ]);

        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $validated['passkey_code']) ?? '');

        if (strlen($clean) !== 12) {
            throw ValidationException::withMessages([
                'passkey_code' => 'Please enter all 12 characters of your passkey code.',
            ]);
        }

        $formattedCode = implode('-', str_split($clean, 4));
        $user = User::query()->where('email', strtolower(trim((string) $validated['email'])))->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'passkey_code' => 'Invalid email or passkey code.',
            ]);
        }

        $issuance = PasskeyIssuance::query()
            ->where('target_user_id', $user->id)
            ->where('status', 'issued')
            ->where('is_locked', true)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $issuance || ! Hash::check($formattedCode, $issuance->enrollment_code_hash)) {
            throw ValidationException::withMessages([
                'passkey_code' => 'Invalid, revoked, or expired passkey code. Ask your officer or generate a new one.',
            ]);
        }

        $issuance->forceFill([
            'status' => 'claimed',
            'claimed_at' => now(),
        ])->save();

        Auth::login($user, true);
        $request->session()->regenerate();

        if ($user->requiresPasswordChange()) {
            return redirect()->route('password.force-change.edit');
        }

        return redirect()->intended(route('dashboard'));
    }
}
