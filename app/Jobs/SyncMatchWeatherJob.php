<?php

namespace App\Jobs;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Services\Providers\OpenMeteoProvider;
use App\Services\SourcePayloadStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncMatchWeatherJob implements ShouldQueue
{
    use Queueable;

    private const WRITE_BATCH_SIZE = 200;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public bool $imminentOnly = false) {}

    public function handle(OpenMeteoProvider $provider, SourcePayloadStoreService $store): void
    {
        $started = microtime(true);
        $source = DataSource::where('slug', 'open-meteo')->firstOrFail();
        $query = FootballMatch::with('homeTeam')->where('kickoff_at', '>', now())->orderBy('kickoff_at');
        $this->imminentOnly ? $query->where('kickoff_at', '<=', now()->addDay()) : $query->where('kickoff_at', '<=', now()->addDays(16));
        $matches = $query->get();
        $saved = $unavailable = 0;

        try {
            $snapshots = [];
            $coordinates = [];
            $now = now();
            foreach ($matches as $match) {
                $weather = $provider->weatherForMatch($match);
                if ($weather['status'] !== 'available') {
                    $unavailable++;
                    continue;
                }
                if (isset($weather['coordinates'])) {
                    $coordinates[] = ['id' => $match->id,
                        'venue_latitude' => $weather['coordinates']['latitude'],
                        'venue_longitude' => $weather['coordinates']['longitude'],
                        'venue_timezone' => $weather['coordinates']['timezone'], 'updated_at' => $now];
                }
                $snapshots[] = ['match_id' => $match->id, 'provider' => 'open-meteo',
                    'forecast_for' => $weather['forecast_for'], 'fetched_at' => $now,
                    'temperature_c' => $weather['temperature_c'], 'precipitation_mm' => $weather['precipitation_mm'],
                    'precipitation_probability' => $weather['precipitation_probability'], 'wind_speed_kmh' => $weather['wind_speed_kmh'],
                    'wind_direction' => $weather['wind_direction'], 'weather_code' => $weather['weather_code'],
                    'raw_payload' => json_encode($weather['raw_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now, 'updated_at' => $now];
            }

            DB::transaction(function () use ($coordinates, $snapshots, &$saved) {
                foreach (array_chunk($coordinates, self::WRITE_BATCH_SIZE) as $batch) {
                    foreach ($batch as $row) DB::table('matches')->where('id', $row['id'])->update(collect($row)->except('id')->all());
                }
                foreach (array_chunk($snapshots, self::WRITE_BATCH_SIZE) as $batch) {
                    DB::table('match_weather_snapshots')->insert($batch);
                    $saved += count($batch);
                }
            });

            $message = "$saved prognoze salvate; $unavailable meciuri fără coordonate sau prognoză disponibilă.";
            $source->update(['status' => 'healthy', 'last_checked_at' => now(), 'last_message' => $message,
                'last_database_records' => $source->records()->count() + DB::table('match_weather_snapshots')->where('provider', 'open-meteo')->count()]);
            $store->log('open-meteo', 'weather', 'healthy', $saved, $message, $started, ['new' => $saved]);
        } catch (Throwable $e) {
            $store->log('open-meteo', 'weather', 'unavailable', $saved,
                'Sincronizarea meteo a eșuat: '.str($e->getMessage())->limit(160), $started, ['new' => $saved]);
            throw $e;
        }
    }
}
