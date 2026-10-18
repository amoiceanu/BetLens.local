<?php

namespace App\Services\Mindmap;

class SourceCatalog
{
    public const GROUPS = ['football' => 'Fotbal · API-uri', 'romania' => 'Surse oficiale România', 'news' => 'Știri & RSS', 'context' => 'Context'];

    public const TYPES = ['rest' => 'REST API · JSON', 'rss' => 'RSS/Atom', 'html' => 'HTML · cu permisiune', 'csv' => 'CSV încărcat manual', 'manual' => 'Introducere / corectare manuală'];

    // Only these existing, known adapters may execute. Metadata added in the UI never enables HTTP requests.
    public const ENDPOINTS = [
        'api-football' => 'https://v3.football.api-sports.io/status',
        'football-data' => 'https://api.football-data.org/v4/competitions',
        'openligadb' => 'https://api.openligadb.de/getavailableleagues',
        'statsbomb-open-data' => 'https://raw.githubusercontent.com/statsbomb/open-data/master/data/competitions.json',
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
