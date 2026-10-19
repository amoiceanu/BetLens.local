<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\DataSource;
use App\Models\ImportRun;
use App\Jobs\ImportUpcomingMatchesJob;
use App\Services\ImportAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminUpcomingMatchesController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'league' => ['nullable', 'integer', 'exists:leagues,id'],
            'team' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $filters['from'] ?? now()->toDateString();
        $to = $filters['to'] ?? now()->addDays(45)->toDateString();
        $matches = FootballMatch::with(['league', 'homeTeam', 'awayTeam', 'actualResult', 'latestBetlensPrediction'])
            ->whereBetween('kickoff_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when(! isset($filters['from']), fn ($query) => $query->where('kickoff_at', '>', now()))
            ->when($filters['league'] ?? null, fn ($query, $league) => $query->where('league_id', $league))
            ->when($filters['team'] ?? null, fn ($query, $team) => $query->where(fn ($both) => $both
                ->whereHas('homeTeam', fn ($q) => $q->where('name', 'like', '%'.$team.'%'))
                ->orWhereHas('awayTeam', fn ($q) => $q->where('name', 'like', '%'.$team.'%'))))
            ->orderBy('kickoff_at')->orderBy('id')->paginate(25)->withQueryString();

        return view('admin.upcoming-matches', [
            'matches' => $matches,
            'leagues' => League::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function import(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'between:1,90']]);
        $days = (int) ($validated['days'] ?? 30);
        $source = DataSource::where('slug', 'openligadb')->firstOrFail();
        $run = DB::transaction(function () use ($source, $days) {
            DataSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $active = ImportRun::where('data_source_id', $source->id)->where('import_method', 'upcoming_matches')
                ->whereIn('status', ['queued', 'running'])->first();
            if ($active) return $active;
            $run = ImportRun::create([
                'data_source_id' => $source->id, 'status' => 'queued',
                'execution_source' => config('betlens.import_execution_source', 'Laravel-local'),
                'import_method' => 'upcoming_matches', 'message' => 'Importul așteaptă în coadă.',
                'metadata' => ['days' => $days, 'stage' => 'queued', 'league' => null, 'predictions_new' => 0],
            ]);
            app(ImportAuditService::class)->syncRun($run);
            ImportUpcomingMatchesJob::dispatch($run->id, $days)->afterCommit();
            return $run;
        });

        return $request->expectsJson()
            ? response()->json(['run_id' => $run->id, 'progress_url' => route('admin.upcoming-matches.progress', $run)], 202)
            : redirect()->route('admin.upcoming-matches')->with('success', 'Importul a fost adăugat în coadă.');
    }

    public function progress(ImportRun $run): JsonResponse
    {
        abort_unless($run->import_method === 'upcoming_matches', 404);
        return response()->json($run->only(['id', 'status', 'records_fetched', 'records_new', 'records_updated',
            'records_skipped', 'records_errors', 'metadata', 'message', 'started_at', 'finished_at']));
    }
}
