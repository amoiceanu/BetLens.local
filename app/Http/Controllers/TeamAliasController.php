<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamAlias;
use App\Services\TeamAliasMatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamAliasController extends Controller
{
    public function index()
    {
        return view('admin.team-aliases', [
            'teams' => Team::with('league')->get()->sortBy(fn (Team $team) => $team->league->name.' '.$team->name),
            'aliases' => TeamAlias::with(['team', 'league'])->orderBy('alias')->paginate(50),
        ]);
    }

    public function store(Request $request, TeamAliasMatcher $matcher)
    {
        $data = $this->validated($request, $matcher);
        TeamAlias::create($data);
        return back()->with('success', 'Aliasul a fost adăugat.');
    }

    public function update(Request $request, TeamAlias $teamAlias, TeamAliasMatcher $matcher)
    {
        $data = $this->validated($request, $matcher, $teamAlias);
        $teamAlias->update($data);
        return back()->with('success', 'Aliasul a fost actualizat.');
    }

    public function destroy(TeamAlias $teamAlias)
    {
        $teamAlias->delete();
        return back()->with('success', 'Aliasul a fost șters.');
    }

    private function validated(Request $request, TeamAliasMatcher $matcher, ?TeamAlias $existing = null): array
    {
        $data = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'provider' => ['required', Rule::in(['all', 'superbet', 'winbet'])],
            'alias' => ['required', 'string', 'max:255'],
        ]);
        $team = Team::findOrFail($data['team_id']);
        $data['alias'] = trim($data['alias']);
        $data['normalized_alias'] = $matcher->normalize($data['alias']);
        $data['league_id'] = $team->league_id;
        if ($data['normalized_alias'] === '') {
            throw ValidationException::withMessages(['alias' => 'Aliasul trebuie să conțină litere sau cifre.']);
        }

        $duplicate = TeamAlias::where('league_id', $team->league_id)
            ->where('provider', $data['provider'])
            ->where('normalized_alias', $data['normalized_alias'])
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['alias' => 'Acest alias există deja pentru liga și operatorul ales.']);
        }

        $conflictsWithTeam = Team::where('league_id', $team->league_id)
            ->whereKeyNot($team->id)
            ->get()
            ->contains(fn (Team $other) => $matcher->normalize($other->name) === $data['normalized_alias']
                || $matcher->normalize($other->short_name) === $data['normalized_alias']);
        if ($conflictsWithTeam) {
            throw ValidationException::withMessages(['alias' => 'Aliasul este deja numele altei echipe din aceeași ligă.']);
        }

        return $data;
    }
}
