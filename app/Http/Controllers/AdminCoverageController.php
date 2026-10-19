<?php

namespace App\Http\Controllers;

use App\Http\Requests\CoverageFilters;
use App\Models\DataSource;
use App\Models\League;
use App\Models\Season;
use App\Models\SourceDataDiscrepancy;
use App\Models\SourceMatchObservation;
use App\Services\Coverage\CoverageMatrix;
use App\Services\Coverage\DiscrepancyQuery;
use App\Services\Coverage\FieldRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCoverageController extends Controller
{
    private function shared(array $filters = []): array
    {
        return ['filters' => $filters, 'fields' => app(FieldRegistry::class)->fields(), 'leagues' => League::orderBy('name')->get(['id', 'name']), 'sources' => DataSource::orderBy('name')->get(['id', 'name']), 'seasons' => Season::when($filters['league'] ?? null, fn ($q, $id) => $q->where('league_id', $id))->orderByDesc('starts_at')->get(['id', 'name']), 'states' => CoverageMatrix::STATES, 'ready' => app(FieldRegistry::class)->ready()];
    }

    private function check(): void
    {
        abort_unless(app(FieldRegistry::class)->ready(), 503, 'Evidența acoperirii nu este încă migrată.');
    }

    public function index(CoverageFilters $request, CoverageMatrix $matrix)
    {
        $filters = array_filter($request->validated(), fn ($v) => $v !== null && $v !== '');
        $shared = $this->shared($filters);
        if (! $shared['ready']) {
            return response()->view('admin.coverage.index', $shared + ['rows' => null, 'summary' => null, 'unassigned' => collect()], 503);
        }
        $rows = $matrix->query($filters)->orderBy('l.name')->orderBy('s.name')->orderByDesc('se.starts_at')->paginate($filters['per_page'] ?? 15)->appends($filters);
        $rows->through(fn ($row) => $matrix->present($row));
        $unassigned = DB::table('source_match_observations as o')->join('data_sources as s', 's.id', '=', 'o.data_source_id')->whereNull('o.league_id')->select('s.id', 's.name')->selectRaw('COUNT(*) as count')->groupBy('s.id', 's.name')->get();

        return view('admin.coverage.index', $shared + ['rows' => $rows, 'summary' => $matrix->summary($filters), 'unassigned' => $unassigned]);
    }

    public function details(CoverageFilters $request, DataSource $source)
    {
        $this->check();
        $filters = $request->validated();
        $fields = app(FieldRegistry::class)->profile($source->slug);
        $query = SourceMatchObservation::with(['game.homeTeam:id,name', 'game.awayTeam:id,name'])->where('data_source_id', $source->id);
        foreach (['league' => 'league_id', 'season' => 'season_id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (($filters['issue'] ?? '') === 'unmapped') {
            $query->whereNull('game_id');
        }
        if (($filters['issue'] ?? '') === 'conflict') {
            $query->whereIn('game_id', SourceDataDiscrepancy::where('status', 'new')->where(fn ($q) => $q->where('source_a_id', $source->id)->orWhere('source_b_id', $source->id))->select('game_id'));
        }
        if (($filters['issue'] ?? '') === 'incomplete') {
            $query->where(function ($q) use ($fields, $filters) {
                $selected = ! empty($filters['field']) ? array_intersect_key($fields, [$filters['field'] => true]) : $fields;
                foreach ($selected as $field => $definition) {
                    $q->orWhere(function ($missing) use ($field, $definition) {
                        if ($definition['finished'] ?? false) {
                            $missing->where('match_status', 'finished');
                        }$missing->whereNull('values->'.$field);
                    });
                }
            });
        }
        $rows = $query->orderByDesc('observed_at')->orderBy('id')->paginate(15)->appends($filters);

        return view('admin.coverage.details', $this->shared($filters) + compact('source', 'rows') + ['profile' => $fields]);
    }

    public function discrepancies(CoverageFilters $request, DiscrepancyQuery $query)
    {
        $this->check();
        $filters = $request->validated();

        return view('admin.coverage.discrepancies', $this->shared($filters) + ['rows' => $query->query($filters)->orderByDesc('last_detected_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 15)->appends($filters)]);
    }

    public function discrepancy(SourceDataDiscrepancy $discrepancy)
    {
        $this->check();
        $discrepancy->load(['sourceA:id,name', 'sourceB:id,name', 'game.homeTeam:id,name', 'game.awayTeam:id,name', 'game.league:id,name']);

        return view('admin.coverage.discrepancy', compact('discrepancy') + ['fields' => app(FieldRegistry::class)->fields()]);
    }

    public function review(Request $request, SourceDataDiscrepancy $discrepancy)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'ignored', 'resolved'])]]);
        $discrepancy->update($data + ['reviewed_by' => $request->session()->get('admin_user_id'), 'reviewed_at' => now(), 'resolved_at' => $data['status'] === 'resolved' ? now() : null]);

        return back()->with('success', 'Starea neconcordanței a fost actualizată. Datele surselor nu au fost modificate.');
    }

    private function csvCell(mixed $value): string
    {
        if ($value === null) {
            return 'Necunoscut';
        }
        $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return preg_match('/^[\s\x{FEFF}]*[=+@\-\t\r]/u', $value) ? "'".$value : $value;
    }

    private function csv(string $filename, array $headers, callable $writer)
    {
        return response()->streamDownload(function () use ($headers, $writer) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $emit = function ($row) use ($out) {
                fputcsv($out, array_map(fn ($value) => $this->csvCell($value), $row), ',', '"', '');
            };
            $emit($headers);
            $writer($emit);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function exportMatrix(CoverageFilters $request, CoverageMatrix $matrix)
    {
        $this->check();
        $query = $matrix->query($request->validated());
        abort_if((clone $query)->count() > config('coverage.export_limit'), 422, 'Prea multe rânduri pentru export. Restrânge filtrele.');

        return $this->csv('acoperire-surse.csv', ['Sursă', 'Ligă', 'Sezon', 'Programate observate', 'Meciuri observate', 'Asociate BetLens', 'Completitudine %', 'Valori disponibile', 'Valori așteptate', 'Medie interval minute', 'Ultima reușită', 'Ultima încercare', 'Rezultat', 'Eșecuri recente', 'Neconcordanțe noi', 'Stare', 'Prospețime', 'Nivel audit', 'Câmpuri disponibile / aplicabile', 'Observație actualizată'], function ($emit) use ($query, $matrix) {
            foreach ($query->orderBy('s.id')->orderBy('l.id')->orderBy('se.id')->cursor() as $row) {
                $r = $matrix->present($row);
                $emit([$r->source_name, $r->league_name, $r->season_name, $r->scheduled_count, $r->observed_count, $r->found_count ?? 0, $r->percent, $r->numerator, $r->denominator, $r->frequency, $r->last_success, $r->last_attempt, $r->attempt_status, $r->recent_errors, $r->conflict_count, $r->state_label, $r->freshness, $r->audit_scope, json_encode(['available' => $r->available_counts, 'eligible' => $r->eligible_counts]), $r->last_observation_at]);
            }
        });
    }

    public function exportDiscrepancies(CoverageFilters $request, DiscrepancyQuery $service)
    {
        $this->check();
        $query = $service->query($request->validated());
        abort_if((clone $query)->count() > config('coverage.export_limit'), 422, 'Prea multe neconcordanțe pentru export. Restrânge filtrele.');

        return $this->csv('neconcordante-surse.csv', ['Meci ID', 'Ligă', 'Sursă A', 'ID A', 'Sursă B', 'ID B', 'Câmp', 'Valoare A', 'Valoare B', 'Severitate', 'Status', 'Detectată', 'Ultima detectare'], function ($emit) use ($query) {
            $query->orderBy('id')->chunkById(500, function ($rows) use ($emit) {
                foreach ($rows as $r) {
                    $emit([$r->game_id, $r->game?->league?->name, $r->sourceA->name, $r->external_a_id, $r->sourceB->name, $r->external_b_id, $r->field, $r->value_a, $r->value_b, $r->severity, $r->status, $r->detected_at?->toIso8601String(), $r->last_detected_at?->toIso8601String()]);
                }
            });
        });
    }
}
