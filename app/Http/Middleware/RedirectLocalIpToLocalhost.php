<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLocalIpToLocalhost
{
    /**
     * In local development, redirect numeric 127.0.0.1 GET requests to
     * `localhost` so browser WebAuthn Passkeys work automatically.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            app()->environment('local')
            && $request->isMethod('GET')
            && $request->getHost() === '127.0.0.1'
        ) {
            $port = $request->getPort();
            $scheme = $request->getScheme();
            $hostWithPort = ($port && ! in_array($port, [80, 443], true))
                ? "localhost:{$port}"
                : 'localhost';

            return redirect()->to("{$scheme}://{$hostWithPort}{$request->getRequestUri()}", 302);
        }

        return $next($request);
    }
}
