<?php

namespace App\Console\Commands;

use App\Services\OddsImportService;
use App\Services\ImportAuditService;
use Illuminate\Console\Command;

class SyncOdds extends Command
{
    protected $signature = 'betlens:sync-odds';
    protected $description = 'Importă cote reale și recalculează recomandările BetLens';

    public function handle(OddsImportService $service, ImportAuditService $audit): int
    {
        $key = \App\Models\DataSource::where('slug','the-odds-api')->first()?->credentialValue();
        if (blank($key)) {
            $this->error('Lipsește cheia pentru The Odds API. Configureaz-o în Admin sau .env.');
            return self::FAILURE;
        }
        $metrics = $service->sync($key);
        $audit->record('the-odds-api', 'odds_sync', $metrics['errors'] ? 'degraded' : 'healthy', [
            'new' => $metrics['odds_new'],
            'updated' => $metrics['odds_updated'],
        ], $metrics['errors'] ? implode('; ', $metrics['errors']) : null);
        $this->table(['Evenimente', 'Asociate', 'Cote noi', 'Actualizate', 'Recomandări', 'Eligibile', 'Credite rămase'], [[
            $metrics['events'], $metrics['matched'], $metrics['odds_new'], $metrics['odds_updated'], $metrics['recommendations'], $metrics['eligible'], $metrics['requests_remaining'] ?? 'n/a',
        ]]);
        foreach ($metrics['errors'] as $error) $this->warn($error);
        return $metrics['errors'] && ! $metrics['matched'] ? self::FAILURE : self::SUCCESS;
    }
}
