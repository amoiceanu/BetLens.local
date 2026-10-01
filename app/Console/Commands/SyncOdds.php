<?php

namespace App\Console\Commands;

use App\Services\OddsImportService;
use Illuminate\Console\Command;

class SyncOdds extends Command
{
    protected $signature = 'betlens:sync-odds';
    protected $description = 'Importă cote reale și recalculează recomandările BetLens';

    public function handle(OddsImportService $service): int
    {
        $key = config('services.odds_api.key');
        if (blank($key)) {
            $this->error('Lipsește ODDS_API_KEY din fișierul .env.');
            return self::FAILURE;
        }
        $metrics = $service->sync($key);
        $this->table(['Evenimente', 'Asociate', 'Cote noi', 'Actualizate', 'Recomandări', 'Eligibile', 'Credite rămase'], [[
            $metrics['events'], $metrics['matched'], $metrics['odds_new'], $metrics['odds_updated'], $metrics['recommendations'], $metrics['eligible'], $metrics['requests_remaining'] ?? 'n/a',
        ]]);
        foreach ($metrics['errors'] as $error) $this->warn($error);
        return $metrics['errors'] && ! $metrics['matched'] ? self::FAILURE : self::SUCCESS;
    }
}
