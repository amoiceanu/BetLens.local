<?php

namespace App\Services\Mindmap;

class SourceCatalog
{
    public const LEVELS = [
        1 => ['name' => 'Date de bază', 'description' => 'Competiții, sezoane, echipe, program și rezultate.', 'details' => 'Acest nivel identifică meciurile și leagă echipele de ligi și sezoane. Rezultatele finale ajută la verificarea și actualizarea istoricului. Nu include cote sau statistici avansate.', 'icon' => 'database'],
        2 => ['name' => 'Statistici și cote', 'description' => 'Indicatori de performanță, xG și prețuri de piață.', 'details' => 'Aici intră datele care descriu performanța sau piața unui meci: șuturi, cornere, xG, clasamente statistice și cote. Cotele sunt păstrate separat de rezultatele reale și pot avea snapshots în timp.', 'icon' => 'chart-no-axes-combined'],
        3 => ['name' => 'Loturi și disponibilitate', 'description' => 'Jucători, line-up-uri, accidentări și suspendări.', 'details' => 'Acest nivel adaugă context despre cine poate juca: loturi, titulari, absențe și suspendări. Disponibilitatea acestor câmpuri depinde de sursă, ligă și planul API.', 'icon' => 'users-round'],
        4 => ['name' => 'Știri și informații externe', 'description' => 'Context editorial; adaptoarele neimplementate sunt marcate.', 'details' => 'Știrile pot explica schimbări de lot sau contextul echipei, dar nu sunt tratate drept rezultate ori statistici. Sursele fără adaptor activ sunt afișate doar ca surse candidate și nu importă automat.', 'icon' => 'newspaper'],
        5 => ['name' => 'Contextul meciului', 'description' => 'Meteo și condiții asociate stadionului și orei.', 'details' => 'Datele meteo sunt asociate cu locația și ora de start a meciului. Ele completează analiza contextuală și nu modifică scorul ori cotele importate.', 'icon' => 'cloud-sun'],
        6 => ['name' => 'Inteligență predictivă', 'description' => 'Estimări și recalculări produse intern de BetLens.', 'details' => 'Acest nivel nu este un furnizor extern: modelele BetLens calculează probabilități și recomandări folosind datele disponibile din celelalte niveluri.', 'icon' => 'brain-circuit'],
    ];

    public const LEVEL_ROLES = [
        'api-football' => [1 => 'Competiții și sezoane; importul API-Football curent stochează metadatele ligilor din România.'],
        'football-data' => [1 => 'Competiții, sezoane, program și rezultate furnizate de football-data.org.'],
        'openligadb' => [1 => 'Echipe, programul meciurilor și scoruri; acestea alimentează și tabelele operaționale BetLens.'],
        'statsbomb-open-data' => [1 => 'Catalogul competițiilor și sezoanelor publicate în StatsBomb Open Data.'],
        'football-data-uk' => [1 => 'Rânduri de meci și rezultate istorice din CSV.', 2 => 'Statistici istorice din CSV, precum șuturi, cornere și cartonașe, unde coloanele sunt prezente.'],
        'sportmonks' => [2 => 'Statistici de meci, xG și clasamente, în limitele acoperirii planului API.', 3 => 'Line-up-uri, jucători și absențe/accidentări, când sunt furnizate pentru liga și planul configurate.'],
        'understat' => [2 => 'Valori xG și indicatori statistici de performanță.'],
        'the-odds-api' => [2 => 'Cote de la operatori pentru piețele configurate; se creează și istoricul snapshots.'],
        'odds-api-io-superbet' => [2 => 'Cote Superbet asociate meciurilor BetLens și arhivate ca date secundare.'],
        'oddspapi-superbet' => [2 => 'Cote Superbet asociate meciurilor BetLens și arhivate ca date secundare.'],
        'gsp-candidate' => [4 => 'Sursă editorială candidată; adaptorul de import nu este implementat.'],
        'digisport-candidate' => [4 => 'Sursă editorială candidată; adaptorul de import nu este implementat.'],
        'open-meteo' => [5 => 'Prognoze și condiții meteo pentru meciurile cu coordonate de stadion disponibile.'],
        'betlens-model' => [6 => 'Calcule interne de probabilități și recomandări; nu face cereri către un API extern.'],
    ];

    public static function roleFor(string $slug, int $level): string
    {
        return self::LEVEL_ROLES[$slug][$level] ?? 'Contribuție secundară la acest nivel; verifică tipul de date și importurile din istoricul sursei.';
    }

    public static function levelApplies(string $slug, int $level): bool
    {
        return ! isset(self::LEVEL_ROLES[$slug]) || isset(self::LEVEL_ROLES[$slug][$level]);
    }

    public const GROUPS = ['football' => 'Fotbal · API-uri', 'romania' => 'Surse oficiale România', 'news' => 'Știri & RSS', 'context' => 'Context', 'model' => 'Model intern'];

    public const TYPES = ['rest' => 'REST API · JSON', 'rss' => 'RSS/Atom', 'html' => 'HTML · cu permisiune', 'csv' => 'CSV încărcat manual', 'manual' => 'Introducere / corectare manuală'];

    // Only these existing, known adapters may execute. Metadata added in the UI never enables HTTP requests.
    public const ENDPOINTS = [
        'api-football' => 'https://v3.football.api-sports.io/status',
        'football-data' => 'https://api.football-data.org/v4/competitions',
        'openligadb' => 'https://api.openligadb.de/getavailableleagues',
        'statsbomb-open-data' => 'https://raw.githubusercontent.com/statsbomb/open-data/master/data/competitions.json',
        'football-data-uk' => 'https://www.football-data.co.uk/mmz4281/2627/E0.csv',
        'sportmonks' => 'https://api.sportmonks.com/v3/football/leagues',
        'open-meteo' => 'https://api.open-meteo.com/v1/forecast',
        'the-odds-api' => 'https://api.the-odds-api.com/v4/sports/',
        'odds-api-io-superbet' => 'https://api.odds-api.io/v3/events',
        'oddspapi-superbet' => 'https://api.oddspapi.io/v4/tournaments',
    ];

    public function entries(): array
    {
        return [
            ['api-football', 'API-Football', 'football', 'rest', 'principal'],
            ['sportmonks', 'Sportmonks', 'football', 'rest', 'alternativă'],
            ['football-data', 'football-data.org', 'football', 'rest', 'suplimentară'],
            ['lpf', 'LPF · Superliga', 'romania', 'html', 'competiții'],
            ['frf', 'FRF · Liga 2 & 3', 'romania', 'html', 'competiții'],
            ['cupa-romaniei', 'Cupa României', 'romania', 'html', 'competiții'],
            ['gsp', 'GSP', 'news', 'rss', 'știri'],
            ['digi-sport', 'Digi Sport RSS', 'news', 'rss', 'știri'],
            ['sport-ro', 'Sport.ro', 'news', 'rss', 'știri'],
            ['gnews', 'GNews API', 'news', 'rest', 'știri'],
            ['newsapi', 'NewsAPI', 'news', 'rest', 'știri'],
            ['open-meteo', 'Open-Meteo', 'context', 'rest', 'meteo'],
            ['arbitri', 'Arbitri FRF/LPF', 'context', 'manual', 'arbitri'],
            ['cluburi', 'Cluburi și stadioane', 'context', 'manual', 'infrastructură'],
        ];
    }
}
