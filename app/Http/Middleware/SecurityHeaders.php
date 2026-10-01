<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response=$next($request);

        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('X-Frame-Options','SAMEORIGIN');
        $response->headers->set('Referrer-Policy','strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy','camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy','same-origin');
        $response->headers->set('Content-Security-Policy',"base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'");

        if($request->isSecure()){
            $response->headers->set('Strict-Transport-Security','max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
