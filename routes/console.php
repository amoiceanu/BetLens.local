<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SyncFootballData;
use App\Jobs\SyncMatchWeatherJob;
use App\Jobs\SyncSportmonksAbsencesJob;
use App\Jobs\SyncSportmonksFixturesJob;
use App\Jobs\SyncSportmonksStatisticsJob;
use App\Jobs\ValidateProviderConflictsJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncFootballData)->hourly()->withoutOverlapping();
Schedule::job(new SyncSportmonksFixturesJob)->hourly()->withoutOverlapping();
Schedule::job(new SyncSportmonksStatisticsJob)->everySixHours()->withoutOverlapping();
Schedule::job(new SyncSportmonksAbsencesJob)->dailyAt('05:30')->withoutOverlapping();
Schedule::job(new SyncMatchWeatherJob)->dailyAt('04:30')->withoutOverlapping();
Schedule::job(new SyncMatchWeatherJob(true))->everyThreeHours()->withoutOverlapping();
Schedule::job(new ValidateProviderConflictsJob)->hourly()->withoutOverlapping();
