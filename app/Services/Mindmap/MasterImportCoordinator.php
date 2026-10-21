<?php

namespace App\Services\Mindmap;

use App\Jobs\AdvanceMasterImport;
use App\Models\DataSource;
use App\Models\ImportRun;
use App\Models\MasterImport;
use App\Services\ImportAuditService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class MasterImportCoordinator
{
    public function start(): MasterImport
    {
        $active = MasterImport::whereIn('status', ['queued', 'running'])->latest('id')->first();
        if ($active) return $active;

        $manager = app(ImportManager::class);
        $items = DataSource::with('levels')->get()
            ->map(function (DataSource $source) use ($manager): array {
                $levels = $source->levels->pluck('level')->map(fn ($level) => (int) $level)
                    ->filter(fn ($level) => SourceCatalog::levelApplies($source->slug, $level));
                $level = $levels->min();
                $status = 'pending';
                $message = null;

                if ($level === null) {
                    $status = 'skipped';
                    $message = 'Sursa nu este atribuită unui nivel L1–L6.';
                } elseif (! $source->active || ! $source->import_enabled) {
                    $status = 'skipped';
                    $message = 'Sursa este dezactivată pentru import.';
                } elseif (in_array($source->import_method, ['unsupported', 'manual_json', 'manual_csv'], true)) {
                    $status = 'skipped';
                    $message = $source->import_method === 'unsupported'
                        ? 'Nu există adaptor automat pentru această sursă.'
                        : 'Sursa necesită încărcarea manuală a unui fișier.';
                } elseif (! $manager->canImport($source)) {
                    $status = 'skipped';
                    $message = 'Sursa nu este configurată sau nu are un importator activ.';
                }

                return ['source_id' => $source->id, 'slug' => $source->slug, 'name' => $source->name,
                    'level' => $level, 'status' => $status, 'message' => $message,
                    'import_run_id' => null, 'started_at' => null, 'finished_at' => null];
            })
            ->sort(function (array $left, array $right): int {
                return ($left['level'] ?? 99) <=> ($right['level'] ?? 99)
                    ?: strnatcasecmp($left['name'], $right['name']);
            })->values()->all();

        $master = MasterImport::create(['status' => 'queued', 'items' => $items, 'current_index' => 0, 'started_at' => now()]);
        AdvanceMasterImport::dispatch($master->id);
        return $master;
    }

    public function resume(MasterImport $master): MasterImport
    {
        if (in_array($master->status, ['queued', 'running'], true)) return $master;

        $items = $master->items ?? [];
        foreach ($items as &$item) {
            if (in_array($item['status'] ?? '', ['failed', 'partial'], true)) {
                $item['status'] = 'pending';
                $item['message'] = 'Reluarea importului de la ultimul checkpoint salvat.';
                $item['finished_at'] = null;
            }
        }
        unset($item);
        $firstPending = collect($items)->search(fn (array $item) => ($item['status'] ?? null) === 'pending');
        if ($firstPending === false) throw ValidationException::withMessages(['master_import' => 'Nu există surse eșuate care să poată fi reluate.']);

        $master->update(['status' => 'queued', 'items' => $items, 'current_index' => $firstPending,
            'current_import_run_id' => null, 'started_at' => now(), 'finished_at' => null]);
        AdvanceMasterImport::dispatch($master->id);
        return $master->fresh();
    }

    public function cancel(MasterImport $master): MasterImport
    {
        return DB::transaction(function () use ($master): MasterImport {
            $master = MasterImport::whereKey($master->id)->lockForUpdate()->firstOrFail();
            if (! in_array($master->status, ['queued', 'running'], true)) {
                throw ValidationException::withMessages(['master_import' => 'Această rulare nu mai este activă.']);
            }

            $items = $master->items ?? [];
            $index = (int) $master->current_index;
            $run = $master->current_import_run_id ? ImportRun::find($master->current_import_run_id) : null;
            $runWasRunning = $run?->status === 'running';
            if ($run && $run->status === 'queued') {
                $run->update(['status' => 'cancelled', 'finished_at' => now(),
                    'message' => 'Import anulat înainte să înceapă, la oprirea Master Import.']);
                app(ImportAuditService::class)->syncRun($run->fresh());
            } elseif ($runWasRunning) {
                $run->update(['metadata' => array_merge($run->metadata ?? [], [
                    'master_import_cancel_requested_at' => now()->toIso8601String(),
                ])]);
            }

            if (isset($items[$index]) && in_array($items[$index]['status'] ?? '', ['queued', 'running'], true)) {
                $items[$index]['status'] = 'cancelled';
                $items[$index]['finished_at'] = now()->toIso8601String();
                $items[$index]['message'] = $runWasRunning
                    ? 'Oprirea a fost cerută; progresul salvat rămâne în audit, iar Master Import a fost resetat.'
                    : 'Master Import oprit la cerere; progresul curent a fost salvat.';
            }

            $master->update(['status' => 'cancelled', 'items' => $items, 'finished_at' => now()]);

            return $master->fresh();
        });
    }

    public function advance(MasterImport $master): void
    {
        $master = MasterImport::find($master->id);
        if (! $master || in_array($master->status, ['completed', 'partial', 'failed', 'cancelled'], true)) return;

        $items = $master->items ?? [];
        $index = (int) $master->current_index;
        $run = $master->current_import_run_id ? ImportRun::with('source')->find($master->current_import_run_id) : null;

        if ($run && in_array($run->status, ['queued', 'running'], true)) {
            $items[$index]['status'] = $run->status;
            $items[$index]['message'] = $run->message ?: ($run->status === 'queued' ? 'Așteaptă procesarea de către worker.' : 'Import în desfășurare.');
            $master->update(['status' => 'running', 'items' => $items, 'started_at' => $master->started_at ?? now()]);
            AdvanceMasterImport::dispatch($master->id)->delay(now()->addSeconds(5));
            return;
        }

        if ($run && $index < count($items)) {
            $items[$index]['status'] = match ($run->status) {
                'completed' => 'completed', 'partial' => 'partial', default => 'failed',
            };
            $items[$index]['message'] = $run->message;
            $items[$index]['finished_at'] = $run->finished_at?->toIso8601String() ?? now()->toIso8601String();
            $master->update(['items' => $items, 'current_import_run_id' => null, 'current_index' => $index + 1]);
            AdvanceMasterImport::dispatch($master->id);
            return;
        }

        while ($index < count($items) && ($items[$index]['status'] ?? null) !== 'pending') $index++;
        if ($index >= count($items)) {
            $hasProblems = collect($items)->contains(fn (array $item) => in_array($item['status'] ?? '', ['failed', 'partial'], true));
            $master->update(['status' => $hasProblems ? 'partial' : 'completed', 'items' => $items,
                'current_index' => count($items), 'finished_at' => now()]);
            return;
        }

        $item = $items[$index];
        $source = DataSource::find($item['source_id'] ?? null);
        if (! $source) {
            $items[$index]['status'] = 'skipped';
            $items[$index]['message'] = 'Sursa nu mai există.';
            $master->update(['items' => $items, 'current_index' => $index + 1]);
            AdvanceMasterImport::dispatch($master->id);
            return;
        }

        $existingRun = ! empty($item['import_run_id']) ? ImportRun::find($item['import_run_id']) : null;
        if ($existingRun && in_array($existingRun->status, ['failed', 'partial'], true)) {
            $run = app(ImportManager::class)->resume($existingRun);
        } else {
            $run = app(ImportManager::class)->enqueue($source);
        }

        $items[$index]['status'] = 'queued';
        $items[$index]['import_run_id'] = $run->id;
        $items[$index]['message'] = 'Import adăugat în coada Laravel.';
        $items[$index]['started_at'] = now()->toIso8601String();
        $master->update(['status' => 'running', 'items' => $items, 'current_index' => $index,
            'current_import_run_id' => $run->id, 'started_at' => $master->started_at ?? now()]);
        AdvanceMasterImport::dispatch($master->id)->delay(now()->addSeconds(5));
    }
}
