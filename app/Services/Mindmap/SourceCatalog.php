<?php

namespace App\Services\Mindmap;

class SourceCatalog
{
    public const LEVELS = [
        1 => ['name' => 'Open APIs', 'description' => 'Competiții, program și rezultate', 'icon' => 'database'],
        2 => ['name' => 'Statistici avansate', 'description' => 'xG, cote și performanță', 'icon' => 'chart-no-axes-combined'],
        3 => ['name' => 'Echipe și jucători', 'description' => 'Loturi și absențe', 'icon' => 'users-round'],
        4 => ['name' => 'Știri și info externe', 'description' => 'Surse candidate, fără import automat', 'icon' => 'newspaper'],
        5 => ['name' => 'Contextul meciului', 'description' => 'Meteo la stadion și ora meciului', 'icon' => 'cloud-sun'],
        6 => ['name' => 'Inteligență predictivă', 'description' => 'Procesori interni BetLens', 'icon' => 'brain-circuit'],
    ];

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
