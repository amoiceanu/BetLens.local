<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\FootballMatch;
use App\Models\League;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SuperbetOddsFeedService
{
    private const ODDS_PAPI_TOURNAMENTS = [
        'premier-league' => ['premier league', 'england'],
        'la-liga' => ['laliga', 'spain'],
        'serie-a' => ['serie a', 'italy'],
        'bundesliga' => ['bundesliga', 'germany'],
        'ligue-1' => ['ligue 1', 'france'],
        'champions-league' => ['champions league', 'europe'],
        'superliga-romania' => ['superliga', 'romania'],
    ];

    public function __construct(
        private SourcePayloadStoreService $store,
        private TeamAliasMatcher $aliases,
    ) {}

    public function sync(DataSource $source, string $key): array
    {
        [$detected, $events] = match ($source->slug) {
            'odds-api-io-superbet' => $this->oddsApiIo($key),
            'oddspapi-superbet' => $this->oddsPapi($key),
            default => throw new RuntimeException('Furnizor de cote necunoscut.'),
        };

        $records = [];
        foreach ($events as $event) {
            $match = $this->matchEvent($event);
            if (!$match) continue;
            $records[] = [
                'external_id' => (string) $event['id'],
                'internal_match_id' => $match->id,
                'home' => $event['home'],
                'away' => $event['away'],
                'kickoff_at' => $event['date'],
                'bookmaker' => 'Superbet',
                'odds_payload' => $event['odds'],
            ];
        }

        $metrics = $this->store->store($source, 'superbet_odds_snapshot', $records);
        return [
            'detected' => $detected,
            'matched' => count($records),
            'metrics' => $metrics,
            'message' => "Conexiune confirmată. {$detected} evenimente disponibile, ".count($records).' asociate meciurilor BetLens; cotele au fost arhivate ca date secundare, fără a înlocui The Odds API.',
        ];
    }

    private function oddsApiIo(string $key): array
    {
        $response = $this->request('https://api.odds-api.io/v3/events', [
            'apiKey' => $key, 'sport' => 'football', 'bookmaker' => 'Superbet', 'limit' => 1000,
        ], 'Odds-API.io');
        $events = $this->items($response->json());
        $matched = [];
        foreach ($events as $event) {
            if (!isset($event['id'], $event['home'], $event['away'], $event['date'])) continue;
            $candidate = ['id' => (string) $event['id'], 'home' => (string) $event['home'], 'away' => (string) $event['away'], 'date' => (string) $event['date'], 'league' => (string) data_get($event, 'league.name', '')];
            if ($this->matchEvent($candidate)) $matched[] = $candidate;
        }

        $odds = [];
        foreach (array_chunk(array_column($matched, 'id'), 10) as $ids) {
            $batch = $this->request('https://api.odds-api.io/v3/odds/multi', [
                'apiKey' => $key, 'eventIds' => implode(',', $ids), 'bookmakers' => 'Superbet', 'markets' => 'ML,Totals,Both Teams To Score',
            ], 'Odds-API.io');
            foreach ($this->items($batch->json()) as $item) {
                if (isset($item['id']) && is_array(data_get($item, 'bookmakers.Superbet'))) $odds[(string) $item['id']] = $item['bookmakers']['Superbet'];
            }
        }

        $result = [];
        foreach ($matched as $event) {
            if (!isset($odds[$event['id']])) continue;
            $event['odds'] = $odds[$event['id']];
            $result[] = $event;
        }
        return [count($events), $result];
    }

    private function oddsPapi(string $key): array
    {
        $leagues = League::whereHas('matches', fn ($query) => $query->whereBetween('kickoff_at', [now()->subHours(2), now()->addDays(14)]))->get()->keyBy('slug');
        $tournaments = $this->items($this->request('https://api.oddspapi.io/v4/tournaments', [
            'apiKey' => $key, 'sportId' => 10,
        ], 'OddsPapi')->json());
        $ids = [];
        foreach ($tournaments as $tournament) {
            foreach (self::ODDS_PAPI_TOURNAMENTS as $slug => [$name, $country]) {
                if (!$leagues->has($slug)) continue;
                if (strtolower((string) ($tournament['categorySlug'] ?? '')) !== $country) continue;
                $actual = $this->normalize((string) ($tournament['tournamentName'] ?? ''));
                if ($actual !== $this->normalize($name)) continue;
                if (isset($tournament['tournamentId'])) $ids[] = (string) $tournament['tournamentId'];
            }
        }
        if (!$ids) return [0, []];

        $fixtures = [];
        $selectedIds = [];
        foreach (array_unique($ids) as $id) {
            $response = $this->request('https://api.oddspapi.io/v4/fixtures', [
                'apiKey' => $key, 'tournamentId' => $id,
                'from' => now()->subHours(2)->utc()->toIso8601String(),
                'to' => now()->addDays(14)->utc()->toIso8601String(),
                'language' => 'en', 'hasOdds' => 'true', 'bookmakers' => 'superbet.ro',
            ], 'OddsPapi');
            foreach ($this->items($response->json()) as $fixture) {
                if (!isset($fixture['fixtureId'], $fixture['participant1Name'], $fixture['participant2Name'], $fixture['startTime'])) continue;
                $candidate = [
                    'home' => (string) $fixture['participant1Name'],
                    'away' => (string) $fixture['participant2Name'],
                    'date' => (string) $fixture['startTime'],
                    'league' => (string) ($fixture['tournamentName'] ?? ''),
                ];
                if (!$this->matchEvent($candidate)) continue;
                $fixtures[(string) $fixture['fixtureId']] = $candidate;
                $selectedIds[] = $id;
            }
        }
        if (!$fixtures) return [0, []];

        $events = [];
        foreach (array_chunk(array_unique($selectedIds), 8) as $chunk) {
            $response = $this->request('https://api.oddspapi.io/v4/odds-by-tournaments', [
                'apiKey' => $key, 'tournamentIds' => implode(',', $chunk), 'bookmakers' => 'superbet.ro', 'language' => 'en', 'verbosity' => 3,
            ], 'OddsPapi');
            $events = array_merge($events, $this->items($response->json()));
        }

        $result = [];
        foreach ($events as $event) {
            $bookmaker = $event['bookmakerOdds']['superbet.ro'] ?? null;
            if (!is_array($bookmaker) || !isset($event['fixtureId'])) continue;
            $fixture = $fixtures[(string) $event['fixtureId']] ?? null;
            if (!$fixture) continue;
            $result[] = [
                'id' => (string) $event['fixtureId'],
                'home' => $fixture['home'],
                'away' => $fixture['away'],
                'date' => $fixture['date'],
                'league' => $fixture['league'],
                'odds' => $bookmaker,
            ];
        }
        return [count($events), $result];
    }

    private function matchEvent(array $event): ?FootballMatch
    {
        if (empty($event['home']) || empty($event['away']) || empty($event['date'])) return null;
        try { $kickoff = Carbon::parse($event['date'])->setTimezone(config('app.timezone')); }
        catch (\Throwable) { return null; }
        $homeAliases = $this->aliases->aliasesFor((string) $event['home'], 'superbet');
        $awayAliases = $this->aliases->aliasesFor((string) $event['away'], 'superbet');
        $leagueName = $this->normalize((string) ($event['league'] ?? ''));
        $candidates = FootballMatch::with(['league', 'homeTeam', 'awayTeam'])
            ->whereBetween('kickoff_at', [$kickoff->copy()->subMinutes(15), $kickoff->copy()->addMinutes(15)])
            ->get()
            ->filter(function (FootballMatch $match) use ($event, $homeAliases, $awayAliases, $leagueName) {
                $localLeague = $this->normalize($match->league->name);
                return ($leagueName === '' || str_contains($leagueName, $localLeague) || str_contains($localLeague, $leagueName))
                    && $this->aliases->matches($match->homeTeam, (string) $event['home'], $homeAliases, 'superbet')
                    && $this->aliases->matches($match->awayTeam, (string) $event['away'], $awayAliases, 'superbet');
            });
        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function request(string $url, array $query, string $provider): \Illuminate\Http\Client\Response
    {
        try { $response = Http::acceptJson()->timeout(25)->retry(2, 500, throw: false)->get($url, $query); }
        catch (\Throwable) { throw new RuntimeException("{$provider}: conexiunea a eșuat."); }
        if (!$response->successful()) throw new RuntimeException("{$provider}: HTTP {$response->status()} (verifică cheia, planul sau limita de cereri).");
        $payload = $response->json();
        if (!is_array($payload)) throw new RuntimeException("{$provider}: răspuns JSON invalid.");
        if (isset($payload['error']) || ($payload['success'] ?? true) === false) throw new RuntimeException("{$provider}: API-ul a respins cererea.");
        return $response;
    }

    private function items(mixed $payload): array
    {
        if (!is_array($payload)) return [];
        if (isset($payload['data']) && is_array($payload['data'])) $payload = $payload['data'];
        return array_is_list($payload) ? array_values(array_filter($payload, 'is_array')) : (isset($payload['id']) || isset($payload['fixtureId']) ? [$payload] : []);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($value)));
    }
}
