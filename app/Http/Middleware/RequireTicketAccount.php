<?php

namespace App\Http\Middleware;

use App\Support\TicketAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTicketAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() && ! TicketAccess::admin($request)) {
            return $request->isMethod('GET')
                ? redirect()->guest(route('user.login'))
                : redirect()->route('user.login');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
