<?php

namespace App\Console\Commands;

use App\Services\OpenLigaDataImporter;
use Illuminate\Console\Command;

class ImportOpenLigaCurrentSeason extends Command
{
    protected $signature = 'betlens:import-openliga-season {--season= : Anul de început al sezonului (implicit anul curent)}';

    protected $description = 'Importă echipele, meciurile și rezultatele sezonului curent din ligile OpenLigaDB configurate';

    public function handle(OpenLigaDataImporter $importer): int
    {
        $season = $this->option('season') === null ? (int) now()->year : filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if ($season === false || $season < 2000 || $season > 2200) {
            $this->error('Sezonul trebuie să fie un an valid între 2000 și 2200.');

            return self::FAILURE;
        }

        $this->line("Se importă sezonul {$season} în sursele configurate OpenLigaDB...");
        $result = $importer->importCurrentSeason($season);
        $this->info("Ligi citite: {$result['leagues']}; echipe noi: {$result['teams']}; meciuri noi: {$result['matches_new']}; meciuri actualizate: {$result['matches_updated']}; rezultate citite: {$result['results']}.");
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
