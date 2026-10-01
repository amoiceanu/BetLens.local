<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Furnizorii reali sunt conectați prin adaptoarele lor după configurarea cheilor API.
    }
    public function boot(): void {}
}
