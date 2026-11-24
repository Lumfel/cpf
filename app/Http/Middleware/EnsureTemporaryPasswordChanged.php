<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTemporaryPasswordChanged
{
    /**
     * Redirect authenticated web users (or block JSON/API v1 requests with 403)
     * who still hold an officer-issued temporary password until they set their
     * own personal password.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user
            && $user->requiresPasswordChange()
            && ! $request->routeIs(
                'password.force-change.*',
                'logout',
                'api.v1.auth.me',
                'api.v1.auth.logout',
                'api.v1.auth.password.*',
            )
        ) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'code' => 'must_change_password_required',
                    'message' => 'You must change your temporary password before accessing this endpoint.',
                ], 403);
            }

            return redirect()->route('password.force-change.edit');
        }

        return $next($request);
    }
}
