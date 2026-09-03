<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; ".
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; ".
            "font-src 'self' https://fonts.gstatic.com; ".
            "script-src 'self' 'unsafe-inline'; ".
            "img-src 'self' data:; ".
            "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
        );
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
