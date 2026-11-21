<?php

namespace App\Http\Controllers\Prototype;

use App\Enums\CpfRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DevRoleSwitcherController extends Controller
{
    public function switch(Request $request, string $role): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 403);

        if ($role === 'reset') {
            $request->session()->forget('cpf_test_role_override');

            return back()->with('status', 'Role override cleared (restored actual account role).');
        }

        $parsed = CpfRole::tryFrom($role);
        abort_if($parsed === null, 422, 'Invalid CPF role.');

        $request->session()->put('cpf_test_role_override', $parsed->value);

        return back()->with('status', "Testing interface as: {$parsed->label()}");
    }
}
