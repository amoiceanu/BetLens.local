<?php

namespace App\Http\Controllers;

use App\Jobs\RunMindmapImport;
use App\Models\DataSource;
use App\Models\ImportDocument;
use App\Models\ImportRun;
use App\Models\SourceRecord;
use App\Services\ImportAuditService;
use App\Services\Imports\DigitalOceanFunctionService;
use App\Services\Mindmap\ImportManager;
use App\Services\Mindmap\MindmapData;
use App\Services\Mindmap\SourceCatalog;
use App\Services\Providers\OpenMeteoProvider;
use App\Services\Providers\SportmonksProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminImportCenterController extends Controller
{
    public function index(Request $request, MindmapData $data)
    {
        $selection = $this->scope($request) + $request->validate(['run_status' => ['nullable', Rule::in(['queued', 'running', 'completed', 'partial', 'failed'])]]);
        $snapshot = $data->snapshot($selection['league'] ?? null, $selection['season'] ?? null);
        $runs = ImportRun::with(['source.levels', 'league', 'documents'])
            ->when($selection['league'] ?? null, fn ($query, $league) => $query->where('league_id', $league))
            ->when($selection['run_status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()->paginate(12)->withQueryString();
        $apiFootballRuns = ImportRun::whereHas('source', fn ($query) => $query->where('slug', 'api-football'))
            ->where('import_method', 'digitalocean_function')->latest()->limit(10)->get();
        return view('admin.mindmap.control-center', $snapshot + ['runs' => $runs,
            'historyRuns' => $runs->getCollection()->concat($apiFootballRuns)->unique('id')->sortByDesc('id')->values(),
            'runStatus' => $selection['run_status'] ?? null]);
    }

    public function import(Request $request, DataSource $source, ImportManager $manager)
    {
        $scope = $this->scope($request);
        if (! $manager->canImport($source, $scope['league'] ?? null) || in_array($source->import_method, ['manual_json', 'manual_csv'], true)) {
            $message = 'Importatorul nu este activ, configurat sau nu suportă filtrarea pe această ligă.';
            return $request->expectsJson() ? response()->json(['message' => $message], 422) : back()->with('warning', $message);
        }
        $run = $manager->enqueue($source, $scope['league'] ?? null, $scope['season'] ?? null);
        return $request->expectsJson() ? response()->json(['run_id' => $run->id, 'status' => 'queued'], 202) : back()->with('success', 'Importul a fost adăugat în coadă.');
    }

    public function importLevel(Request $request, int $level, ImportManager $manager)
    {
        abort_unless(isset(SourceCatalog::LEVELS[$level]), 404);
        return $this->importMany($request, DataSource::whereHas('levels', fn ($query) => $query->where('level', $level))->get(), $manager);
    }

    public function importAll(Request $request, ImportManager $manager)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        return $this->importMany($request, DataSource::whereHas('levels')->get(), $manager);
    }

    private function importMany(Request $request, $sources, ImportManager $manager)
    {
        $scope = $this->scope($request);
        $ids = []; $queued = [];
        foreach ($sources as $source) {
            if (! $manager->canImport($source, $scope['league'] ?? null) || in_array($source->import_method, ['manual_json', 'manual_csv'], true)) continue;
            if ($source->importRuns()->whereIn('status', ['queued', 'running'])->exists()) continue;
            try {
                $run = $manager->enqueue($source, $scope['league'] ?? null, $scope['season'] ?? null);
                $ids[] = $run->id; $queued[] = ['id' => $run->id, 'source_id' => $source->id];
            } catch (ValidationException) {
                continue;
            }
        }
        $message = count($ids).' joburi adăugate în coadă; sursele neconfigurate, nesuportate sau deja în lucru au fost omise.';
        return $request->expectsJson() ? response()->json(['run_ids' => $ids, 'runs' => $queued, 'message' => $message], 202) : back()->with(count($ids) ? 'success' : 'warning', $message);
    }

    public function update(Request $request, DataSource $source)
    {
        $values = $request->validate([
            'active' => ['required', 'boolean'], 'import_enabled' => ['required', 'boolean'],
            'import_frequency' => ['required', Rule::in(['manual', 'hourly', 'six_hourly', 'twelve_hourly', 'daily', 'weekly'])],
            'request_timeout' => ['required', 'integer', 'between:5,30'],
            'level' => ['required', 'integer', 'between:1,6'],
        ]);
        $source->update(collect($values)->except('level')->all());
        $source->levels()->firstOrCreate(['level' => $values['level']]);
        return $request->expectsJson() ? response()->json(['saved' => true]) : back()->with('success', 'Configurația sursei a fost salvată.');
    }

    public function mapField(Request $request, DataSource $source)
    {
        abort_unless(in_array($source->import_method, ['manual_json', 'manual_csv'], true), 422);
        $values = $request->validate([
            'external_field' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'internal_field' => ['required', Rule::in(['home_team_name', 'away_team_name', 'kickoff_at', 'competition_name', 'home_score', 'away_score'])],
        ]);
        DB::table('source_field_mappings')->updateOrInsert(
            ['data_source_id' => $source->id, 'external_field' => $values['external_field']],
            ['internal_field' => $values['internal_field'], 'updated_at' => now(), 'created_at' => now()]
        );
        return back()->with('success', 'Maparea a fost salvată pentru importurile manuale viitoare.');
    }

    public function mapCompetition(Request $request, DataSource $source)
    {
        $values = $request->validate([
            'league_id' => ['required', 'integer', 'exists:leagues,id'],
            'coverage_status' => ['required', Rule::in(['unverified', 'supported', 'unsupported'])],
            'external_competition_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ]);
        DB::table('data_source_competitions')->updateOrInsert(
            ['data_source_id' => $source->id, 'league_id' => $values['league_id']],
            ['coverage_status' => $values['coverage_status'], 'external_competition_id' => $values['external_competition_id'] ?? null,
                'verified_at' => $values['coverage_status'] === 'unverified' ? null : now(), 'updated_at' => now(), 'created_at' => now()]
        );
        return back()->with('success', 'Acoperirea declarată a fost salvată. Nu activează automat un import pe ligă.');
    }

    public function upload(Request $request, DataSource $source, ImportManager $manager)
    {
        abort_unless(in_array($source->import_method, ['manual_json', 'manual_csv'], true), 422);
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:json,csv,txt']]);
        $scope = $this->scope($request);
        $file = $request->file('file');
        $extension = $source->import_method === 'manual_csv' ? 'csv' : 'json';
        abort_unless(strtolower($file->getClientOriginalExtension()) === $extension, 422);
        $run = $manager->enqueue($source, $scope['league'] ?? null, $scope['season'] ?? null, false);
        $disk = config('betlens.raw_import_disk', 'local');
        $checksum = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs('imports/'.$run->id, 'upload.'.$extension, $disk);
        if (! $path) {
            $run->update(['status' => 'failed', 'message' => 'Fișierul nu a putut fi salvat.', 'finished_at' => now()]);
            app(ImportAuditService::class)->syncRun($run);
            abort(500, 'Fișierul nu a putut fi salvat.');
        }
        ImportDocument::create(['import_run_id' => $run->id, 'disk' => $disk, 'path' => $path,
            'content_type' => $file->getMimeType(), 'checksum' => $checksum, 'bytes' => $file->getSize()]);
        RunMindmapImport::dispatch($run->id);
        return back()->with('success', 'Fișierul a fost adăugat în coada de import.');
    }

    public function progress(ImportRun $run)
    {
        return response()->json($run->only(['id', 'status', 'records_fetched', 'records_new', 'records_updated', 'records_skipped', 'records_unchanged', 'records_errors', 'duration_ms', 'metadata', 'message', 'started_at', 'finished_at']));
    }

    public function preview(DataSource $source)
    {
        return response()->json(['records' => SourceRecord::where('data_source_id', $source->id)->latest()->limit(5)->get(['record_type', 'external_key', 'payload', 'last_seen_at'])]);
    }

    public function testConnection(DataSource $source)
    {
        if ($source->slug === 'api-football') {
            try {
                $items = app(DigitalOceanFunctionService::class)->fetch('api_football_l1');
                return response()->json(['status' => 'success', 'message' => 'Funcția DigitalOcean a răspuns: '.count($items).' competiții primite. Nu s-au scris date în MySQL.']);
            } catch (\Throwable) {
                return response()->json(['status' => 'failed', 'message' => 'Conexiunea cu funcția DigitalOcean nu a reușit. Verifică URL-ul, tokenul și logurile serverului.'], 422);
            }
        }
        if ($source->import_method !== 'existing' || ! isset(SourceCatalog::ENDPOINTS[$source->slug]) || $source->verification_url !== SourceCatalog::ENDPOINTS[$source->slug] || ! $source->is_configured) {
            return response()->json(['message' => 'Nu există un endpoint aprobat și configurat pentru test.'], 422);
        }
        if (in_array($source->slug, ['odds-api-io-superbet', 'oddspapi-superbet'], true)) {
            return response()->json(['message' => 'Testul separat nu este disponibil pentru acest adaptor; importul existent rămâne disponibil.'], 422);
        }
        try {
            if ($source->slug === 'sportmonks') $ok = (bool) app(SportmonksProvider::class)->verifyConnection();
            elseif ($source->slug === 'open-meteo') $ok = (bool) app(OpenMeteoProvider::class)->verifyConnection();
            else {
                $http = Http::timeout(min(30, $source->request_timeout ?: 20))->retry(2, 250, throw: false);
                $key = $source->credentialValue();
                if ($source->slug === 'football-data') $http = $http->withHeader('X-Auth-Token', $key);
                if ($source->slug === 'api-football') $http = $http->withHeader('x-apisports-key', $key);
                $url = $source->verification_url;
                if ($source->slug === 'the-odds-api') $url .= '?apiKey='.urlencode($key);
                $ok = $http->get($url)->successful();
            }
            return response()->json(['status' => $ok ? 'success' : 'failed', 'message' => $ok ? 'Conexiune reușită. Nu s-au importat date.' : 'Conexiunea nu a reușit. Nu s-au importat date.']);
        } catch (\Throwable) {
            return response()->json(['status' => 'failed', 'message' => 'Conexiunea nu a reușit. Verifică acreditările și logurile serverului.']);
        }
    }

    private function scope(Request $request): array
    {
        return $request->validate([
            'league' => ['nullable', 'integer', 'exists:leagues,id'],
            'season' => ['nullable', 'integer', Rule::exists('seasons', 'id')->when($request->filled('league'), fn ($rule) => $rule->where('league_id', $request->integer('league')))],
        ]);
    }
}
