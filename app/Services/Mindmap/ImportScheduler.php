<?php

namespace App\Services\Mindmap;

use App\Models\DataSource;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ImportScheduler
{
    public const MINUTES = ['hourly' => 60, 'six_hourly' => 360, 'twelve_hourly' => 720, 'daily' => 1440, 'weekly' => 10080];

    public function dispatchDue(): void
    {
        DataSource::where('active', true)->where('import_enabled', true)
            ->whereIn('import_frequency', array_keys(self::MINUTES))->get()->each(function (DataSource $source) {
                if (in_array($source->import_method, ['manual_json', 'manual_csv'], true) || ! app(ImportManager::class)->canImport($source)) return;
                $last = $source->importRuns()->latest()->first();
                if ($last && $last->created_at->addMinutes(self::MINUTES[$source->import_frequency])->isFuture()) return;
                try {
                    app(ImportManager::class)->enqueue($source);
                } catch (ValidationException) {
                    Log::info('Scheduled source import skipped because another run is active.', ['source_id' => $source->id]);
                }
            });
    }
}
