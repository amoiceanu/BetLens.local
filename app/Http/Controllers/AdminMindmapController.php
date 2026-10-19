<?php

namespace App\Http\Controllers;

use App\Jobs\VerifyDataSource;
use App\Models\ApplicationSetting;
use App\Models\DataSource;
use App\Services\Mindmap\MindmapData;
use App\Services\Mindmap\SourceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminMindmapController extends Controller
{
    public function index(Request $request, MindmapData $data)
    {
        $selection = $request->validate([
            'league' => ['nullable', 'integer', 'exists:leagues,id'],
            'season' => ['nullable', 'integer', Rule::exists('seasons', 'id')->when($request->filled('league'), fn ($rule) => $rule->where('league_id', $request->integer('league')))],
        ]);

        return view('admin.mindmap.index', $data->snapshot($selection['league'] ?? null, $selection['season'] ?? null));
    }

    public function store(Request $request)
    {
        $values = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:data_sources,name'],
            'level' => ['nullable', 'integer', 'between:1,6'],
            'import_method' => ['nullable', Rule::in(['manual_json', 'manual_csv', 'unsupported'])],
            'group' => ['required', Rule::in(array_keys(SourceCatalog::GROUPS))],
            'type' => ['required', Rule::in(array_keys(SourceCatalog::TYPES))],
            'competition' => ['nullable', 'string', 'max:120'],
            'html_permitted' => ['accepted_if:type,html'],
        ]);
        DB::transaction(function () use ($values) {
            $level = (int) ($values['level'] ?? match ($values['group']) {
                'football' => 1, 'romania' => 3, 'news' => 4, 'context' => 5, default => 1,
            });
            $method = $values['import_method'] ?? 'unsupported';
            $source = DataSource::create([
                'name' => $values['name'], 'slug' => Str::slug($values['name']).'-'.Str::lower(Str::random(8)),
                'category' => SourceCatalog::GROUPS[$values['group']], 'description' => 'Sursă adăugată manual · importator de configurat.',
                'active' => false, 'status' => 'not_configured', 'import_method' => $method,
                'import_enabled' => in_array($method, ['manual_json', 'manual_csv'], true),
            ]);
            $source->levels()->create(['level' => $level]);
            ApplicationSetting::create(['key' => 'mindmap.source.'.$source->id, 'group' => 'mindmap', 'value' => $values]);
        });

        return to_route('admin.mindmap')->with('success', 'Sursă adăugată. Importator neconfigurat; niciun apel extern nu a fost efectuat.');
    }

    public function import(DataSource $source, MindmapData $data)
    {
        if (! $data->canImport($source)) {
            return back()->with('warning', 'Importator neconfigurat. Este necesar un adaptor real cu endpoint aprobat.');
        }
        if (! $source->is_configured) {
            return back()->with('warning', 'Acreditări neconfigurate. Nu a fost pornit niciun import.');
        }
        $claimed = DataSource::whereKey($source->id)
            ->where(fn ($query) => $query->whereNull('last_manual_verification_at')->orWhere('last_manual_verification_at', '<=', now()->subMinutes(5)))
            ->update(['last_manual_verification_at' => now()]);
        if (! $claimed) {
            return back()->with('warning', 'Verificarea acestei surse este limitată la o execuție la 5 minute.');
        }
        VerifyDataSource::dispatchSync($source->id);

        return back()->with('success', 'Adaptorul existent a fost executat. Consultă starea și jurnalul ultimei sincronizări; un răspuns API nu garantează importul meciurilor.');
    }
}
