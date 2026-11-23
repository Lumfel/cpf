<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cookieAppearance = $request->cookie('appearance');
        $appearance = in_array($cookieAppearance, ['light', 'dark', 'sunrise', 'joy', 'vice', 'system'], true)
            ? $cookieAppearance
            : 'system';

        View::share('appearance', $appearance);

        return $next($request);
    }
}
