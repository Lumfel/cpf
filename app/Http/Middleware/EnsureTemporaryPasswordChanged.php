<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTemporaryPasswordChanged
{
    /**
     * Redirect authenticated users who still hold an officer-issued temporary
     * password to set their own personal password before accessing the dashboard
     * or generating their Attendance QR Code.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user
            && $user->requiresPasswordChange()
            && ! $request->routeIs('password.force-change.*')
            && ! $request->routeIs('logout')
        ) {
            return redirect()->route('password.force-change.edit');
        }

        return $next($request);
    }
}
