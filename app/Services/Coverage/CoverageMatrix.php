<?php

namespace App\Services\Coverage;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CoverageMatrix
{
    public const STATES = ['complete' => 'Completă', 'partial' => 'Parțială', 'old' => 'Date vechi', 'conflict' => 'Cu neconcordanțe', 'empty' => 'Fără date'];

    private function alias(string $alias): string
    {
        return DB::connection()->getTablePrefix().$alias;
    }

    private function auditAggregate(string $table, bool $scoped): Builder
    {
        $time = $scoped ? 'COALESCE(finished_at, imported_at)' : 'finished_at';
        $type = $scoped ? 'import_type' : 'type';
        $query = DB::table($table)->select('provider')->selectRaw("MAX(id) as latest_id, MAX(CASE WHEN status = 'healthy' THEN {$time} END) as last_success, MIN(CASE WHEN status = 'healthy' THEN {$time} END) as first_success, COUNT(DISTINCT CASE WHEN status = 'healthy' THEN {$time} END) as success_times, COUNT(DISTINCT CASE WHEN status = 'healthy' THEN {$type} END) as success_types")
            ->selectRaw("SUM(CASE WHEN status IN ('unavailable','degraded','failed','not_configured') AND {$time} >= ? THEN 1 ELSE 0 END) as recent_errors", [now()->subDays(config('coverage.recent_days'))->format('Y-m-d H:i:s')]);
        if ($scoped) {
            $query->addSelect('league_id', 'season_id')->whereNotNull('league_id')->groupBy('provider', 'league_id', 'season_id');
        } else {
            $query->whereNotIn('type', ['provider_conflict', 'conflict_validation'])->groupBy('provider');
        }

        return $query;
    }

    public function expressions(?string $field = null): array
    {
        $c = $this->alias('c');
        $sa = $this->alias('sa');
        $ga = $this->alias('ga');
        $dc = $this->alias('dc');
        $present = "COALESCE({$c}.completed_slots,0)";
        $eligible = "COALESCE({$c}.expected_slots,0)";
        if ($field) { // The field is validated against the central registry before reaching SQL.
            $present = "COALESCE(JSON_EXTRACT({$c}.available_counts, '$.{$field}'),0)";
            $eligible = "COALESCE(JSON_EXTRACT({$c}.eligible_counts, '$.{$field}'),0)";
        }
        $success = "CASE WHEN {$sa}.latest_id IS NOT NULL THEN {$sa}.last_success ELSE {$ga}.last_success END";
        $state = "CASE WHEN COALESCE({$dc}.conflict_count,0)>0 THEN 'conflict' WHEN COALESCE({$c}.found_count,0)=0 OR {$eligible}=0 OR {$present}=0 THEN 'empty' WHEN {$success} IS NOT NULL AND {$success} < ? THEN 'old' WHEN {$present} >= {$eligible} THEN 'complete' ELSE 'partial' END";

        return compact('present', 'eligible', 'success', 'state');
    }

    public function query(array $filters): Builder
    {
        $d = $this->alias('d');
        $m = $this->alias('m');
        $unknownLeague = $this->alias('unknown_league');
        $c = $this->alias('c');
        $se = $this->alias('se');
        $sa = $this->alias('sa');
        $dc = $this->alias('dc');
        $conflicts = DB::table('source_data_discrepancies as d')->join('matches as m', 'm.id', '=', 'd.game_id')
            ->selectRaw("{$d}.source_a_id as source_id,{$m}.league_id,{$m}.season_id,{$d}.id")->where('d.status', 'new');
        $other = DB::table('source_data_discrepancies as d')->join('matches as m', 'm.id', '=', 'd.game_id')
            ->selectRaw("{$d}.source_b_id as source_id,{$m}.league_id,{$m}.season_id,{$d}.id")->where('d.status', 'new');
        $counts = DB::query()->fromSub($conflicts->unionAll($other), 'pairs')->select('source_id', 'league_id', 'season_id')->selectRaw('COUNT(*) as conflict_count')->groupBy('source_id', 'league_id', 'season_id');
        $seasons = DB::table('seasons')->select('id', 'league_id', 'name', 'starts_at');
        $unknownSeasons = DB::table('leagues as unknown_league')->selectRaw("NULL as id, {$unknownLeague}.id as league_id, NULL as name, NULL as starts_at")->whereExists(fn ($q) => $q->selectRaw('1')->from('source_coverage_cells')->whereColumn('league_id', 'unknown_league.id')->whereNull('season_id'));
        $query = DB::table('data_sources as s')->crossJoin('leagues as l')->leftJoinSub($seasons->unionAll($unknownSeasons), 'se', fn ($join) => $join->on('se.league_id', '=', 'l.id'))
            ->leftJoin('source_coverage_cells as c', fn ($join) => $join->on('c.data_source_id', '=', 's.id')->on('c.league_id', '=', 'l.id')->whereRaw("({$c}.season_id = {$se}.id OR ({$c}.season_id IS NULL AND {$se}.id IS NULL))"))
            ->leftJoinSub($this->auditAggregate('data_sync_logs', false), 'ga', fn ($join) => $join->on('ga.provider', '=', 's.slug'))
            ->leftJoin('data_sync_logs as gl', 'gl.id', '=', 'ga.latest_id')
            ->leftJoinSub($this->auditAggregate('import_audits', true), 'sa', fn ($join) => $join->on('sa.provider', '=', 's.slug')->on('sa.league_id', '=', 'l.id')->whereRaw("({$sa}.season_id = {$se}.id OR ({$sa}.season_id IS NULL AND {$se}.id IS NULL))"))
            ->leftJoin('import_audits as sl', 'sl.id', '=', 'sa.latest_id')
            ->leftJoinSub($counts, 'dc', fn ($join) => $join->on('dc.source_id', '=', 's.id')->on('dc.league_id', '=', 'l.id')->whereRaw("({$dc}.season_id = {$se}.id OR ({$dc}.season_id IS NULL AND {$se}.id IS NULL))"));
        foreach (['source' => 's.id', 'league' => 'l.id', 'season' => 'se.id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, (int) $filters[$key]);
            }
        }
        if (! empty($filters['q'])) {
            $search = '%'.addcslashes($filters['q'], '%_\\').'%';
            $fieldMatches = collect(app(FieldRegistry::class)->fields())->filter(fn ($value) => mb_stripos($value['label'], $filters['q']) !== false)->keys()->all();
            $query->where(function ($where) use ($search, $fieldMatches) {
                $where->where('s.name', 'like', $search)->orWhere('l.name', 'like', $search);
                foreach ($fieldMatches as $field) {
                    $where->orWhereRaw("COALESCE(JSON_EXTRACT(c.eligible_counts,'$.{$field}'),0) > 0");
                }
            });
        }
        ['present' => $present,'eligible' => $eligible,'success' => $success,'state' => $state] = $this->expressions($filters['field'] ?? null);
        $cutoff = now()->subHours(config('coverage.fresh_hours'))->format('Y-m-d H:i:s');
        if (! empty($filters['field'])) {
            $definition = app(FieldRegistry::class)->fields()[$filters['field']];
            $query->where('s.slug', ($definition['weather'] ?? false) ? '=' : '!=', 'open-meteo');
        }
        if (! empty($filters['state'])) {
            $query->whereRaw("({$state}) = ?", [$cutoff, $filters['state']]);
        }

        $s = $this->alias('s');
        $l = $this->alias('l');
        $ga = $this->alias('ga');
        $sl = $this->alias('sl');
        $gl = $this->alias('gl');
        return $query->selectRaw("{$s}.id as source_id,{$s}.name as source_name,{$s}.slug as provider,{$s}.import_method,{$s}.active as source_active,{$s}.import_enabled,{$l}.id as league_id,{$l}.name as league_name,{$se}.id as season_id,{$se}.name as season_name,{$c}.observed_count,{$c}.found_count,{$c}.scheduled_count,{$c}.ambiguous_count,{$c}.available_counts,{$c}.eligible_counts,{$c}.last_observation_at,{$present} as numerator,{$eligible} as denominator,{$success} as last_success,COALESCE({$dc}.conflict_count,0) as conflict_count,CASE WHEN {$sa}.latest_id IS NOT NULL THEN {$sa}.recent_errors ELSE {$ga}.recent_errors END as recent_errors,CASE WHEN {$sa}.latest_id IS NOT NULL THEN COALESCE({$sl}.finished_at,{$sl}.started_at,{$sl}.imported_at) ELSE COALESCE({$gl}.finished_at,{$gl}.started_at) END as last_attempt,CASE WHEN {$sa}.latest_id IS NOT NULL THEN {$sl}.status ELSE {$gl}.status END as attempt_status,CASE WHEN {$sa}.latest_id IS NOT NULL THEN {$sa}.first_success ELSE {$ga}.first_success END as first_success,CASE WHEN {$sa}.latest_id IS NOT NULL THEN {$sa}.success_times ELSE {$ga}.success_times END as success_times,CASE WHEN {$sa}.latest_id IS NOT NULL THEN {$sa}.success_types ELSE {$ga}.success_types END as success_types,CASE WHEN {$sa}.latest_id IS NOT NULL THEN 'league' ELSE 'global' END as audit_scope,{$state} as coverage_state", [$cutoff]);
    }

    public function present(object $row): object
    {
        $row->percent = $row->denominator > 0 ? round(100 * $row->numerator / $row->denominator, 1) : null;
        $row->state_label = self::STATES[$row->coverage_state];
        $row->freshness = 'Necunoscut';
        $row->age = null;
        $row->frequency = null;
        if ($row->last_success) {
            $hours = max(0, Carbon::parse($row->last_success)->diffInHours(now()));
            $row->age = round($hours, 1);
            $row->freshness = $hours <= config('coverage.fresh_hours') ? 'Proaspătă' : ($hours <= config('coverage.critical_hours') ? 'Învechită' : 'Critică');
        }
        if ($row->success_types == 1 && $row->success_times >= 2 && $row->first_success && $row->last_success) {
            $row->frequency = round(abs(Carbon::parse($row->first_success)->diffInMinutes(Carbon::parse($row->last_success))) / ($row->success_times - 1), 1);
        }
        $row->available_counts = json_decode($row->available_counts ?? '{}', true) ?? [];
        $row->eligible_counts = json_decode($row->eligible_counts ?? '{}', true) ?? [];

        return $row;
    }

    public function summary(array $filters): array
    {
        // Aggregate over the SQL-filtered matrix, not the current page; no observation payloads are scanned on GET.
        $base = DB::query()->fromSub($this->query($filters), 'matrix');
        $summary = $base->selectRaw('AVG(CASE WHEN denominator>0 THEN 100.0*numerator/denominator END) as mean')->first();

        return ['active_sources' => DB::table('data_sources')->where('active', true)->count(), 'leagues' => DB::table('leagues')->count(), 'mean' => $summary->mean === null ? null : round($summary->mean, 1),
            'stale_sources' => DB::query()->fromSub($this->query($filters), 'matrix')->whereNotNull('last_success')->where('last_success', '<', now()->subHours(config('coverage.fresh_hours')))->distinct()->count('source_id'),
            'conflicts' => app(DiscrepancyQuery::class)->query(array_replace($filters, ['status' => 'new']))->count(),
            'failed' => DB::table('import_audits')->when($filters['source'] ?? null, fn ($q, $id) => $q->whereIn('provider', DB::table('data_sources')->where('id', $id)->select('slug')))->when($filters['league'] ?? null, fn ($q, $id) => $q->where('league_id', $id))->when($filters['season'] ?? null, fn ($q, $id) => $q->where('season_id', $id))->whereIn('status', ['unavailable', 'degraded', 'failed', 'not_configured'])->where('imported_at', '>=', now()->subDays(config('coverage.recent_days')))->count()];
    }
}
