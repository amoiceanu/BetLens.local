<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId=$request->session()->get('admin_user_id');
        if(!$userId||!User::whereKey($userId)->where('is_admin',true)->exists()){
            $request->session()->forget('admin_user_id');
            return redirect()->guest(route('admin.login'));
        }

        return $next($request);
    }
}
