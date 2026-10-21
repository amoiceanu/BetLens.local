<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamAlias;
use App\Services\TeamAliasMatcher;
use App\Services\TeamAliasResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeamAliasController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['all', 'active', 'review'])],
            'sort' => ['nullable', Rule::in(['team', 'alias', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $query = $this->filteredAliases($filters);
        $sort = $filters['sort'] ?? 'alias';
        $direction = $filters['direction'] ?? 'asc';
        if ($sort === 'team') {
            $query->orderBy(Team::select('name')->whereColumn('teams.id', 'team_aliases.team_id'), $direction);
        } else {
            $query->orderBy($sort, $direction);
        }

        return view('admin.team-aliases', [
            'teams' => collect(),
            'aliases' => $query->with(['team.league'])->paginate(25)->withQueryString(),
            'filters' => array_merge(['q' => '', 'status' => 'all', 'sort' => 'alias', 'direction' => 'asc'], $filters),
            'totalAliases' => TeamAlias::count(),
            'canonicalTeams' => TeamAlias::where('status', 'active')->distinct('team_id')->count('team_id'),
            'reviewCount' => TeamAlias::where('status', 'review')->count(),
        ]);
    }

    public function teams(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim($filters['q'] ?? '');
        $teams = Team::query()
            ->with('league:id,name')
            ->when($term !== '', fn ($query) => $query->where(fn ($search) => $search
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('short_name', 'like', '%'.$term.'%')))
            ->orderBy('name')->limit(30)->get(['id', 'league_id', 'name', 'short_name', 'crest']);

        return response()->json($teams->map(fn (Team $team) => [
            'id' => $team->id,
            'name' => $team->name,
            'short_name' => $team->short_name,
            'crest' => $team->crest,
            'league' => $team->league?->name,
        ]));
    }

    public function normalize(Request $request, TeamAliasMatcher $matcher)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return response()->json(['normalized' => $matcher->normalize($data['q'] ?? '')]);
    }

    public function store(Request $request, TeamAliasMatcher $matcher, TeamAliasResolver $resolver)
    {
        $data = $this->validated($request, $matcher);
        try {
            DB::transaction(fn () => TeamAlias::create($data));
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw ValidationException::withMessages(['alias' => 'Aliasul există deja.']);
            }
            throw $exception;
        }
        $resolver->forgetCache();

        return back()->with('success', 'Aliasul global a fost adăugat.');
    }

    public function update(Request $request, TeamAlias $teamAlias, TeamAliasMatcher $matcher, TeamAliasResolver $resolver)
    {
        $data = $this->validated($request, $matcher, $teamAlias);
        $teamAlias->update($data);
        $resolver->forgetCache();

        return back()->with('success', 'Aliasul global a fost actualizat.');
    }

    public function destroy(TeamAlias $teamAlias, TeamAliasResolver $resolver)
    {
        $teamAlias->delete();
        $resolver->forgetCache();

        return back()->with('success', 'Aliasul a fost șters; echipa canonică și datele importate au rămas intacte.');
    }

    public function destroyMany(Request $request, TeamAliasResolver $resolver)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'exists:team_aliases,id']]);
        $deleted = TeamAlias::whereIn('id', $data['ids'])->delete();
        $resolver->forgetCache();

        return back()->with('success', "Au fost șterse {$deleted} aliasuri. Echipele și datele importate nu au fost modificate.");
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['all', 'active', 'review'])],
        ]);
        $rows = $this->filteredAliases($filters)->with('team')->orderBy('alias')->get();

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Echipă canonică', 'Alias global', 'Formă normalizată', 'Status'], ';');
            foreach ($rows as $row) fputcsv($output, [$row->team?->name, $row->alias, $row->normalized_alias, $row->status], ';');
            fclose($output);
        }, 'betlens-aliasuri-globale.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredAliases(array $filters)
    {
        return TeamAlias::query()
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['q'] ?? null), fn ($query) => $query->where(fn ($search) => $search
                ->where('alias', 'like', '%'.$filters['q'].'%')
                ->orWhere('normalized_alias', 'like', '%'.$filters['q'].'%')
                ->orWhereHas('team', fn ($team) => $team->where('name', 'like', '%'.$filters['q'].'%')
                    ->orWhere('short_name', 'like', '%'.$filters['q'].'%'))));
    }

    private function validated(Request $request, TeamAliasMatcher $matcher, ?TeamAlias $existing = null): array
    {
        $data = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'alias' => ['required', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'review'])],
        ]);
        $team = Team::findOrFail($data['team_id']);
        $data['alias'] = trim($data['alias']);
        $data['normalized_alias'] = $matcher->normalize($data['alias']);
        $data['league_id'] = $team->league_id; // retained as legacy context; never used as alias identity
        $data['provider'] = 'all'; // retained for compatibility with old rows and imports
        $data['status'] = 'active';
        if ($data['normalized_alias'] === '') {
            throw ValidationException::withMessages(['alias' => 'Aliasul trebuie să conțină litere sau cifre.']);
        }

        $sameKey = TeamAlias::where('normalized_alias', $data['normalized_alias'])
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get();
        if ($sameKey->contains(fn (TeamAlias $alias) => (int) $alias->team_id === (int) $team->id)) {
            throw ValidationException::withMessages(['alias' => 'Aliasul există deja pentru această echipă.']);
        }
        if ($sameKey->isNotEmpty()) {
            throw ValidationException::withMessages(['alias' => 'Conflict global: această formă normalizată este deja asociată unei alte echipe. Nu s-a făcut nicio asociere.']);
        }

        $canonicalConflict = Team::query()->whereKeyNot($team->id)->get(['id', 'name', 'short_name'])
            ->contains(fn (Team $other) => $matcher->normalize($other->name) === $data['normalized_alias']
                || $matcher->normalize((string) $other->short_name) === $data['normalized_alias']);
        if ($canonicalConflict) {
            throw ValidationException::withMessages(['alias' => 'Conflict global: aliasul corespunde numelui canonic al altei echipe.']);
        }

        return $data;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
