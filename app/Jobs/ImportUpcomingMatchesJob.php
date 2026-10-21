<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\ImportAuditService;
use App\Services\OpenLigaDataImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportUpcomingMatchesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 540;

    public function __construct(public int $runId, public int $days, public string $mode = 'upcoming') {}

    public function handle(OpenLigaDataImporter $importer, ImportAuditService $audit): void
    {
        $run = ImportRun::findOrFail($this->runId);
        if ($run->finished_at) return;
        $run->update(['status' => 'running', 'started_at' => now(), 'message' => 'Se pregătește conexiunea cu OpenLigaDB.']);
        $audit->syncRun($run);

        $progress = function (string $stage, string $league, array $metrics) use ($run, $audit) {
            $run->update([
                'records_fetched' => $metrics['read'],
                'records_new' => $metrics['matches_new'],
                'records_updated' => $metrics['matches_updated'],
                'records_skipped' => $metrics['matches_unchanged'],
                'records_errors' => count($metrics['errors']),
                'metadata' => ['days' => $this->days, 'stage' => $stage, 'league' => $league,
                    'leagues_done' => $metrics['leagues'], 'predictions_new' => $metrics['predictions_new'],
                    'predictions_unchanged' => $metrics['predictions_unchanged'], 'errors' => $metrics['errors']],
                'message' => match ($stage) {
                    'connecting' => 'Conectare la OpenLigaDB pentru '.$league.'.',
                    'writing' => 'Date preluate; se scriu meciurile și estimările pentru '.$league.'.',
                    'league_done' => 'Liga '.$league.' a fost procesată.',
                    default => 'Importul ligii '.$league.' a întâmpinat o eroare.',
                },
            ]);
            $audit->syncRun($run);
        };
        $result = $this->mode === 'past_results'
            ? $importer->refreshPastMatchResults($progress)
            : $importer->importUpcomingMatches($this->days, $progress);

        $run->update([
            'status' => $result['errors'] ? 'partial' : 'completed',
            'records_fetched' => $result['read'], 'records_new' => $result['matches_new'],
            'records_updated' => $result['matches_updated'], 'records_skipped' => $result['matches_unchanged'],
            'records_errors' => count($result['errors']),
            'metadata' => ['days' => $this->days, 'mode' => $this->mode, 'stage' => 'finished', 'league' => null,
                'leagues_done' => $result['leagues'], 'predictions_new' => $result['predictions_new'],
                'predictions_unchanged' => $result['predictions_unchanged'], 'errors' => $result['errors']],
            'message' => $result['errors'] ? 'Import încheiat cu avertismente.' : 'Import încheiat cu succes.',
            'finished_at' => now(),
        ]);
        $audit->syncRun($run);
    }

    public function failed(?Throwable $exception): void
    {
        $run = ImportRun::find($this->runId);
        if (! $run || $run->finished_at) return;
        $run->update(['status' => 'failed', 'records_errors' => $run->records_errors + 1,
            'message' => 'Importul a eșuat. Verifică logurile serverului.', 'finished_at' => now(),
            'metadata' => array_merge($run->metadata ?? [], ['stage' => 'failed'])]);
        app(ImportAuditService::class)->syncRun($run);
    }
}
