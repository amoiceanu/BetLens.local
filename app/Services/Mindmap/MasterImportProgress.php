<?php

namespace App\Services\Mindmap;

use App\Models\ImportRun;

class MasterImportProgress
{
    public function planFor(?string $slug, ?string $method = null): array
    {
        if ($method === 'internal_model' || $slug === 'betlens-model') {
            return ['calculation' => 'Calcule model', 'finalization' => 'Salvare rezultate'];
        }

        if ($slug === 'api-football' && in_array($method, ['digitalocean_function', 'api_football_local'], true)) {
            $local = $method === 'api_football_local';
            return [
                'connection' => $local ? 'Conectare locală la API-Football' : 'Conectare la Function-DO',
                'collection' => $local ? 'Preluare competiții și sezoane RO' : 'Preluare date',
                'persistence' => 'Scriere competiții și sezoane',
                'finalization' => 'Finalizare și audit',
            ];
        }

        $steps = [
            'connection' => 'Conectare la furnizor',
            'collection' => 'Colectare și validare date',
            'persistence' => 'Scriere în baza de date',
        ];

        if (in_array($slug, ['openligadb', 'the-odds-api'], true)) {
            $steps['calculation'] = $slug === 'openligadb' ? 'Calcul estimări BetLens' : 'Calcul recomandări';
        }

        $steps['finalization'] = 'Finalizare și audit';

        return $steps;
    }

    public function initialize(ImportRun $run): void
    {
        $run->loadMissing('source');
        $metadata = $run->metadata ?? [];
        $metadata['master_progress'] = [
            'current_step' => null,
            'message' => 'Jobul așteaptă procesarea de către worker.',
            'updated_at' => now()->toIso8601String(),
            'steps' => collect($this->planFor($run->source?->slug, $run->import_method))
                ->map(fn (string $label, string $key) => [
                    'key' => $key, 'label' => $label, 'status' => 'pending', 'message' => 'În așteptare',
                    'counts' => [], 'started_at' => null, 'finished_at' => null,
                ])->values()->all(),
        ];
        $run->update(['metadata' => $metadata]);
    }

    public function start(ImportRun $run, string $key, string $message, array $counts = []): void
    {
        $this->update($run, $key, $message, $counts, false);
    }

    public function complete(ImportRun $run, string $key, string $message, array $counts = []): void
    {
        $this->update($run, $key, $message, $counts, true);
    }

    public function pendingSteps(?string $slug, ?string $method = null): array
    {
        return collect($this->planFor($slug, $method))->map(fn (string $label, string $key) => [
            'key' => $key, 'label' => $label, 'status' => 'pending', 'message' => 'În așteptare',
            'counts' => [], 'started_at' => null, 'finished_at' => null,
        ])->values()->all();
    }

    private function update(ImportRun $run, string $key, string $message, array $counts, bool $complete): void
    {
        $run->refresh()->loadMissing('source');
        $metadata = $run->metadata ?? [];
        $progress = $metadata['master_progress'] ?? null;
        if (! is_array($progress) || ! isset($progress['steps'])) {
            $this->initialize($run);
            $run->refresh();
            $metadata = $run->metadata ?? [];
            $progress = $metadata['master_progress'];
        }

        $steps = $progress['steps'];
        foreach ($steps as $index => $step) {
            if ($step['key'] !== $key) continue;

            $now = now()->toIso8601String();
            $steps[$index] = array_merge($step, [
                'status' => $complete ? 'completed' : 'running',
                'message' => $message,
                'counts' => $counts,
                'started_at' => $step['started_at'] ?? $now,
                'finished_at' => $complete ? $now : null,
            ]);
            break;
        }

        $progress['steps'] = $steps;
        $progress['current_step'] = $complete ? ($progress['current_step'] ?? $key) : $key;
        $progress['message'] = $message;
        $progress['updated_at'] = now()->toIso8601String();
        $metadata['master_progress'] = $progress;
        $run->update(['metadata' => $metadata]);
    }
}
