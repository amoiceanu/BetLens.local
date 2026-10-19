<?php

namespace App\Console\Commands;

use App\Services\OpenLigaDataImporter;
use Illuminate\Console\Command;

class ImportUpcomingMatches extends Command
{
    protected $signature = 'betlens:import-upcoming {--days=30 : Intervalul viitor, 1-90 zile}';

    protected $description = 'Importă meciurile viitoare din OpenLigaDB și salvează estimările BetLens disponibile';

    public function handle(OpenLigaDataImporter $importer): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 90) {
            $this->error('Intervalul trebuie să fie între 1 și 90 de zile.');
            return self::FAILURE;
        }
        $result = $importer->importUpcomingMatches($days);
        $this->info("Meciuri noi: {$result['matches_new']}; actualizate: {$result['matches_updated']}; estimări noi: {$result['predictions_new']}.");
        foreach ($result['errors'] as $error) $this->error($error);

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
