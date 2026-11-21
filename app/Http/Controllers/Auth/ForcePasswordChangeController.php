<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class ForcePasswordChangeController extends Controller
{
    use PasswordValidationRules;

    public function edit(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->requiresPasswordChange()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/ForcePasswordChange', [
            'email' => $user->email,
            'fullName' => $user->name,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user, 401);

        $validated = $request->validate([
            'password' => $this->passwordRules(),
        ]);

        $user->forceFill([
            'password' => Hash::make((string) $validated['password']),
            'must_change_password' => false,
            'temporary_password_issued_at' => null,
        ])->save();

        return redirect()->route('dashboard')->with(
            'status',
            'Your personal password has been created and your Attendance QR Code is now ready.',
        );
    }
}
