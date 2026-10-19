<?php

namespace App\Console\Commands;

use App\Services\UpcomingPredictionBackfill;
use Illuminate\Console\Command;

class BackfillUpcomingPredictions extends Command
{
    protected $signature = 'betlens:backfill-predictions {--dry-run : Arată câte estimări pot fi generate, fără scriere}';

    protected $description = 'Populează cotele echitabile BetLens 1/X/2 pentru meciurile viitoare existente';

    public function handle(UpcomingPredictionBackfill $backfill): int
    {
        $counts = $backfill->run((bool) $this->option('dry-run'));
        $this->info(json_encode($counts, JSON_UNESCAPED_UNICODE));

        return $counts['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
