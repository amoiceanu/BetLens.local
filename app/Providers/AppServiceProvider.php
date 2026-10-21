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
        $this->app->singleton(\App\Services\Coverage\ObservationProjector::class);
        $this->app->scoped(\App\Services\Coverage\FieldRegistry::class);
    }
    public function boot(): void
    {
        \Illuminate\Support\Facades\View::composer('admin.partials.navigation', function ($view): void {
            $view->with('failedJobCount', app(\App\Http\Controllers\AdminFailedJobsController::class)->count());
        });
        $this->app->extend('migration.repository', function ($repository, $app) {
            $migrations = $app['config']['database.migrations'];
            $table = is_array($migrations) ? ($migrations['table'] ?? null) : $migrations;

            return new \App\Database\UnprefixedMigrationRepository($app['db'], $table);
        });
        \App\Models\ExternalIdMapping::saved(fn($mapping)=>app(\App\Services\Coverage\ObservationProjector::class)->remap($mapping));
        \App\Models\MatchWeatherSnapshot::saved(function($snapshot){
            $projector=app(\App\Services\Coverage\ObservationProjector::class);
            $projector->safely(function()use($snapshot,$projector){
                $source=\App\Models\DataSource::where('slug',$snapshot->provider)->first();$game=\App\Models\FootballMatch::find($snapshot->match_id);
                if(!$source||!$game)return;
                $values=[];foreach(['temperature_c','precipitation_mm','wind_speed_kmh'] as $field)$values[$field]=$snapshot->$field;
                $projector->record($source,['external_id'=>'weather:'.$game->id,'match_status'=>$game->status,'values'=>$values],$game,$snapshot->fetched_at);
            });
        });
        RateLimiter::for('admin-login',fn(Request $request)=>Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('login',fn(Request $request)=>Limit::perMinute(5)->by($request->ip().'|'.mb_strtolower((string)$request->input('email'))));
        RateLimiter::for('register',fn(Request $request)=>Limit::perHour(5)->by($request->ip()));
    }
}
