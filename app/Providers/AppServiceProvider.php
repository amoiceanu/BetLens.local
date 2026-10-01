<?php
namespace App\Providers;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Furnizorii reali sunt conectați prin adaptoarele lor după configurarea cheilor API.
    }
    public function boot(): void
    {
        RateLimiter::for('admin-login',fn(Request $request)=>Limit::perMinute(5)->by($request->ip()));
    }
}
