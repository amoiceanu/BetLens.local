<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Services\Mindmap\MatchLevelSimulator;
use App\Support\DatabaseConnectionStatus;
use Illuminate\Http\Request;

class AdminSimulatorController extends Controller
{
    public function index(Request $request, MatchLevelSimulator $simulator)
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'match_id' => ['nullable', 'integer', 'min:1'],
            'levels_submitted' => ['nullable', 'in:1'],
            'levels' => ['nullable', 'array'],
            'levels.*' => ['integer', 'between:1,6'],
        ]);
        $enabled = [];
        $availability = [];
        $database = DatabaseConnectionStatus::current();
        $connected = app()->environment('testing') || $database['online'];
        $search = trim($input['q'] ?? '');
        $matches = collect();
        $match = null;
        $simulation = $reference = null;
        $warning = null;

        if ($connected) {
            $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->whereHas('homeTeam', fn ($team) => $team->where('name', 'like', '%'.$search.'%'))
                            ->orWhereHas('awayTeam', fn ($team) => $team->where('name', 'like', '%'.$search.'%'));
                        if (ctype_digit($search)) $query->orWhereKey((int) $search);
                    });
                }, fn ($query) => $query->where('kickoff_at', '>=', now()->subDay()))
                ->orderBy('kickoff_at')->limit(80)->get();
            $match = isset($input['match_id'])
                ? FootballMatch::with(['homeTeam', 'awayTeam', 'league'])->findOrFail($input['match_id'])
                : ($search === '' ? $matches->first() : null);
            if ($match && ! $matches->contains('id', $match->id)) $matches->prepend($match);

            if ($match?->data_status === 'conflict') {
                $warning = 'Meciul are un conflict între furnizori. Simularea este suspendată până la rezolvare.';
            } elseif ($match) {
                $reference = $simulator->simulate($match, [1, 2, 3, 4, 5, 6]);
                foreach ($reference['steps'] as $step) {
                    $availability[$step['level']] = in_array($step['status'], ['applied', 'unmodeled'], true);
                }
                $requested = isset($input['levels_submitted'])
                    ? array_values(array_unique(array_map('intval', $input['levels'] ?? [])))
                    : array_keys(array_filter($availability));
                $enabled = array_values(array_filter($requested, fn ($level) => $availability[$level] ?? false));
                $simulation = $enabled === array_keys(array_filter($availability))
                    ? $reference
                    : $simulator->simulate($match, $enabled);
            }
        } else {
            $warning = 'Simulatorul este disponibil numai când aplicația este conectată la DigitalOcean BetLens-Prod. Nu se folosesc date din baza locală.';
        }

        return view('admin.simulator', compact('database', 'connected', 'search', 'matches', 'match', 'enabled', 'availability', 'simulation', 'reference', 'warning'));
    }
}
