<?php

namespace App\Contracts;

use App\Models\FootballMatch;

interface WeatherDataProviderInterface
{
    public function verifyConnection(): array;
    public function weatherForMatch(FootballMatch $match): array;
}
