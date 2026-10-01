<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\Odd;
use App\Models\ProviderMatchMapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OddsImportService
{
    private const SPORTS = [
        'premier-league' => 'soccer_epl',
        'la-liga' => 'soccer_spain_la_liga',
        'serie-a' => 'soccer_italy_serie_a',
        'bundesliga' => 'soccer_germany_bundesliga',
        'ligue-1' => 'soccer_france_ligue_one',
        'champions-league' => 'soccer_uefa_champs_league',
    ];

    public function __construct(private MatchAnalysisService $analysis) {}

    public function sync(string $apiKey): array
    {
        $metrics = ['events' => 0, 'matched' => 0, 'unmatched' => 0, 'odds_new' => 0, 'odds_updated' => 0, 'odds_unchanged' => 0, 'recommendations' => 0, 'eligible' => 0, 'requests_remaining' => null, 'errors' => []];
        $regions = config('services.odds_api.regions', 'eu');
        $markets = config('services.odds_api.markets', 'h2h,totals');

        foreach (self::SPORTS as $slug => $sportKey) {
            $league = League::where('slug', $slug)->whereHas('matches', fn ($query) => $query->where('kickoff_at', '>', now()))->first();
            if (! $league) continue;
            try {
                $response = Http::acceptJson()->timeout(30)->retry(2, 400, throw: false)
                    ->get("https://api.the-odds-api.com/v4/sports/{$sportKey}/odds", [
                        'apiKey' => $apiKey,
                        'regions' => $regions,
                        'markets' => $markets,
                        'oddsFormat' => 'decimal',
                        'dateFormat' => 'iso',
                    ]);
                $metrics['requests_remaining'] = $response->header('x-requests-remaining') ?? $metrics['requests_remaining'];
                if (! $response->successful()) throw new \RuntimeException("{$sportKey}: HTTP {$response->status()}");
                $events = $response->json();
                if (! is_array($events)) continue;
                $metrics['events'] += count($events);
                foreach ($events as $event) $this->importEvent($league, $sportKey, $event, $metrics);
                $analysis = $this->analysis->generateForLeague($league);
                $metrics['recommendations'] += $analysis['created'] + $analysis['updated'];
                $metrics['eligible'] += $analysis['eligible'];
            } catch (\Throwable $exception) {
                $metrics['errors'][] = $exception->getMessage();
            }
        }
        return $metrics;
    }

    private function importEvent(League $league, string $sportKey, array $event, array &$metrics): void
    {
        if (empty($event['id']) || empty($event['commence_time']) || empty($event['home_team']) || empty($event['away_team'])) return;
        $match = $this->resolveMatch($league, $event);
        if (! $match) { $metrics['unmatched']++; return; }
        $metrics['matched']++;
        ProviderMatchMapping::updateOrCreate(
            ['provider' => 'the-odds-api', 'external_id' => (string) $event['id']],
            ['match_id' => $match->id, 'sport_key' => $sportKey, 'home_name' => $event['home_team'], 'away_name' => $event['away_team'], 'kickoff_at' => Carbon::parse($event['commence_time'])]
        );

        foreach ($event['bookmakers'] ?? [] as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $market) {
                foreach ($market['outcomes'] ?? [] as $outcome) {
                    $normalized = $this->marketSelection($market['key'] ?? '', $outcome, $event);
                    if (! $normalized || ! isset($outcome['price']) || (float) $outcome['price'] <= 1) continue;
                    [$marketKey, $selection] = $normalized;
                    $marketModel = Market::where('key', $marketKey)->where('active', true)->first();
                    if (! $marketModel) continue;
                    $capturedAt = Carbon::parse($market['last_update'] ?? $bookmaker['last_update'] ?? now());
                    $identity = ['match_id' => $match->id, 'market_id' => $marketModel->id, 'selection' => $selection, 'provider' => (string) ($bookmaker['key'] ?? 'the-odds-api')];
                    $odd = Odd::where($identity)->first();
                    $price = round((float) $outcome['price'], 2);
                    if (! $odd) {
                        $odd = Odd::create($identity + ['price' => $price, 'captured_at' => $capturedAt]);
                        $metrics['odds_new']++;
                    } elseif ((float) $odd->price !== $price) {
                        $odd->update(['price' => $price, 'captured_at' => $capturedAt]);
                        $metrics['odds_updated']++;
                    } else {
                        $odd->update(['captured_at' => $capturedAt]);
                        $metrics['odds_unchanged']++;
                    }
                    $lastSnapshot = DB::table('odds_snapshots')->where('odds_id', $odd->id)->orderByDesc('captured_at')->first();
                    if (! $lastSnapshot || (float) $lastSnapshot->price !== $price || (string) $lastSnapshot->captured_at !== $capturedAt->format('Y-m-d H:i:s')) {
                        DB::table('odds_snapshots')->insert(['odds_id' => $odd->id, 'price' => $price, 'captured_at' => $capturedAt, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
        }
    }

    private function resolveMatch(League $league, array $event): ?FootballMatch
    {
        $mapping = ProviderMatchMapping::where('provider', 'the-odds-api')->where('external_id', (string) $event['id'])->first();
        if ($mapping) return $mapping->match;
        $kickoff = Carbon::parse($event['commence_time'])->setTimezone(config('app.timezone'));
        $candidates = FootballMatch::with(['homeTeam', 'awayTeam'])
            ->where('league_id', $league->id)
            ->whereBetween('kickoff_at', [$kickoff->copy()->subHours(18), $kickoff->copy()->addHours(18)])
            ->get();
        return $candidates->map(function ($match) use ($event, $kickoff) {
            $home = $this->similarity($match->homeTeam->name, $event['home_team']);
            $away = $this->similarity($match->awayTeam->name, $event['away_team']);
            $timePenalty = min(.15, abs($match->kickoff_at->diffInMinutes($kickoff)) / 7200);
            return ['match' => $match, 'score' => ($home + $away) / 2 - $timePenalty, 'home' => $home, 'away' => $away];
        })->filter(fn ($candidate) => $candidate['home'] >= .48 && $candidate['away'] >= .48)
            ->sortByDesc('score')->first()['match'] ?? null;
    }

    private function marketSelection(string $market, array $outcome, array $event): ?array
    {
        if ($market === 'h2h') {
            $name = $this->normalizeName((string) ($outcome['name'] ?? ''));
            $selection = $name === $this->normalizeName($event['home_team']) ? '1' : ($name === $this->normalizeName($event['away_team']) ? '2' : ($name === 'draw' ? 'X' : null));
            return $selection ? ['result', $selection] : null;
        }
        if ($market === 'totals' && abs((float) ($outcome['point'] ?? 0) - 2.5) < .01) {
            $name = strtolower((string) ($outcome['name'] ?? ''));
            return str_starts_with($name, 'over') ? ['over_25', 'Peste 2.5'] : (str_starts_with($name, 'under') ? ['over_25', 'Sub 2.5'] : null);
        }
        return null;
    }

    private function similarity(string $left, string $right): float
    {
        $left = $this->normalizeName($left); $right = $this->normalizeName($right);
        if ($left === $right) return 1;
        similar_text($left, $right, $percent);
        $leftTokens = array_filter(explode(' ', $left)); $rightTokens = array_filter(explode(' ', $right));
        $tokenScore = count(array_intersect($leftTokens, $rightTokens)) / max(1, min(count($leftTokens), count($rightTokens)));
        return max($percent / 100, $tokenScore);
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(Str::ascii($name));
        $name = preg_replace('/\b(fc|afc|cf|sc|sv|vfb|vfl|fk|club|football|calcio|de|the|1)\b/', ' ', $name);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $name));
    }
}
