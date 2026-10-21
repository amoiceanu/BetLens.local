<?php

namespace App\Http\Controllers;

use App\Models\ImportRun;
use App\Models\DataSource;
use App\Models\MasterImport;
use App\Jobs\AdvanceMasterImport;
use App\Services\Mindmap\MasterImportCoordinator;
use App\Services\Mindmap\MasterImportProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class MasterImportController extends Controller
{
    public function start(MasterImportCoordinator $coordinator): JsonResponse
    {
        $master = $coordinator->start();
        return response()->json(['id' => $master->id, 'progress_url' => route('data-sources.master-import.progress', $master),
            'cancel_url' => route('data-sources.master-import.cancel', $master),
            'status' => $master->status, 'message' => 'Master importul a fost pornit.'], $master->status === 'queued' ? 202 : 200);
    }

    public function resume(MasterImport $master, MasterImportCoordinator $coordinator): JsonResponse
    {
        try {
            $master = $coordinator->resume($master);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['id' => $master->id, 'progress_url' => route('data-sources.master-import.progress', $master),
            'cancel_url' => route('data-sources.master-import.cancel', $master),
            'status' => $master->status, 'message' => 'Sursele eșuate au fost reluate de la ultimul checkpoint.'], 202);
    }

    public function recover(MasterImport $master): JsonResponse
    {
        if (! in_array($master->status, ['queued', 'running'], true)) {
            return response()->json(['message' => 'Această rulare nu mai este activă.'], 422);
        }

        AdvanceMasterImport::dispatch($master->id);

        return response()->json(['id' => $master->id, 'progress_url' => route('data-sources.master-import.progress', $master),
            'status' => $master->status, 'message' => 'Coordonarea a fost retrimisă în coada Laravel; importurile deja pornite nu sunt duplicate.'], 202);
    }

    public function cancel(MasterImport $master, MasterImportCoordinator $coordinator): JsonResponse
    {
        try {
            $master = $coordinator->cancel($master);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['id' => $master->id, 'status' => $master->status,
            'message' => 'Master Import a fost oprit. Progresul curent a fost salvat; o nouă rulare va începe de la zero.']);
    }

    public function progress(MasterImport $master): JsonResponse
    {
        $items = $master->items ?? [];
        $runs = ImportRun::whereIn('id', collect($items)->pluck('import_run_id')->filter())->get()->keyBy('id');
        $sources = DataSource::whereIn('id', collect($items)->pluck('source_id')->filter())->get()->keyBy('id');
        $progressService = app(MasterImportProgress::class);
        $items = collect($items)->map(function (array $item) use ($runs, $sources, $progressService, $master): array {
            $run = ! empty($item['import_run_id']) ? $runs->get($item['import_run_id']) : null;
            $source = ! empty($item['source_id']) ? $sources->get($item['source_id']) : null;
            $sourceProgress = $source?->verification_progress ?? [];
            $item['run_status'] = $run?->status;
            $sourceStage = $sourceProgress['stage'] ?? null;
            if ($sourceStage === 'finished') $sourceStage = 'running';
            $item['phase'] = ($item['status'] ?? null) === 'cancelled'
                ? 'cancelled'
                : ($run?->status === 'queued'
                ? 'queued'
                : ($run?->status === 'running' ? ($sourceStage ?? 'running') : ($run?->status ?? $item['status'] ?? 'pending')));
            $item['phase_label'] = $this->phaseLabel($item['phase']);
            $activeSourceMessage = ($sourceProgress['stage'] ?? null) === 'finished' ? null : ($sourceProgress['message'] ?? null);
            $item['message'] = ($item['status'] ?? null) === 'cancelled' ? ($item['message'] ?? null) : (($run?->status === 'running' ? $activeSourceMessage : null)
                ?: $run?->message
                ?: ($item['message'] ?? null));
            $item['records_fetched'] = $run?->records_fetched ?? 0;
            $item['records_new'] = $run?->records_new ?? 0;
            $item['records_updated'] = $run?->records_updated ?? 0;
            $item['records_errors'] = $run?->records_errors ?? 0;
            $metadata = $run?->metadata ?? [];
            $masterProgress = data_get($metadata, 'master_progress', []);
            $slug = $source?->slug ?? ($item['slug'] ?? null);
            $isCancelled = $master->status === 'cancelled' || ($item['status'] ?? null) === 'cancelled';
            if ($isCancelled) unset($metadata['master_progress']);
            $item['progress'] = $metadata;
            $method = $slug === 'api-football' ? 'api_football_local' : $run?->import_method;
            $pendingSteps = $progressService->pendingSteps($slug, $method);
            $savedSteps = $masterProgress['steps'] ?? $pendingSteps;
            $item['steps'] = $isCancelled ? $pendingSteps : collect($savedSteps)->map(function (array $step) use ($pendingSteps): array {
                $plan = collect($pendingSteps)->firstWhere('key', $step['key'] ?? null);
                return $plan ? array_merge($step, ['label' => $plan['label']]) : $step;
            })->all();
            $item['current_step_key'] = $isCancelled ? null : ($masterProgress['current_step'] ?? null);
            $item['progress_message'] = $masterProgress['message'] ?? $item['message'];
            $item['step_processed'] = data_get($metadata, 'source_records_cursor', data_get($metadata, 'api_football_write_cursor', 0));
            $item['step_total'] = data_get($metadata, 'source_records_total', data_get($metadata, 'api_football_write_total', 0));
            $item['stage_started_at'] = data_get($sourceProgress, 'steps.'.($sourceStage ?? '').'.started_at');
            $item['last_activity_at'] = $run?->updated_at?->toIso8601String()
                ?? ($run ? $source?->updated_at?->toIso8601String() : null)
                ?? ($item['started_at'] ?? null);
            return $item;
        })->values();
        $done = $items->whereIn('status', ['completed', 'partial', 'failed', 'skipped', 'cancelled'])->count();
        $active = $items->whereIn('status', ['queued', 'running'])->count();
        $progressItems = $items->reject(fn (array $item) => ($item['status'] ?? null) === 'skipped');
        $progressTotal = $progressItems->count();
        $progressDone = $progressItems->whereIn('status', ['completed', 'partial', 'failed', 'cancelled'])->count();
        $statusLabel = match ($master->status) {
            'queued' => 'În coadă', 'running' => 'În desfășurare', 'completed' => 'Finalizat',
            'partial' => 'Finalizat cu avertismente', 'failed' => 'Eșuat', 'cancelled' => 'Oprit la cerere', default => $master->status,
        };
        $current = $items->get((int) $master->current_index);
        $startedAt = $master->started_at ?? $master->created_at;
        $lastActivity = $current['last_activity_at'] ?? $master->updated_at?->toIso8601String();
        $isStalled = in_array($master->status, ['queued', 'running'], true)
            && $lastActivity
            && Carbon::parse($lastActivity)->lt(now()->subSeconds(120));

        return response()->json(['id' => $master->id, 'status' => $master->status, 'status_label' => $statusLabel,
            'total' => $items->count(), 'done' => $done, 'active' => $active,
            'progress_total' => $progressTotal, 'progress_done' => $progressDone,
            'completed' => $items->where('status', 'completed')->count(),
            'partial' => $items->where('status', 'partial')->count(), 'failed' => $items->where('status', 'failed')->count(),
            'skipped' => $items->where('status', 'skipped')->count(), 'items' => $items,
            'current_index' => (int) $master->current_index,
            'current_source' => $current['name'] ?? null, 'current_phase' => $current['phase_label'] ?? null,
            'current_message' => $current['message'] ?? null, 'last_activity_at' => $lastActivity,
            'is_stalled' => $isStalled, 'started_at' => $startedAt?->toIso8601String(),
            'finished_at' => $master->finished_at?->toIso8601String()]);
    }

    private function phaseLabel(?string $phase): string
    {
        return match ($phase) {
            'queued' => 'Așteaptă workerul Laravel',
            'connecting' => 'Conectare la sursă',
            'fetching' => 'Descărcare date',
            'importing' => 'Scriere în baza de date',
            'analyzing' => 'Analiză și procesare',
            'finished', 'completed' => 'Sursă finalizată',
            'failed' => 'Sursă eșuată',
            'cancelled' => 'Oprit la cerere',
            'partial' => 'Finalizat cu avertismente',
            'running' => 'Procesare în curs',
            'pending' => 'În așteptare',
            default => ucfirst((string) $phase),
        };
    }
}
