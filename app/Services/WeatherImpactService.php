<?php

namespace App\Services;

use App\Models\MatchWeatherSnapshot;

class WeatherImpactService
{
    public function assess(?MatchWeatherSnapshot $weather,string $selection): array
    {
        if(!$weather||!str_starts_with($selection,'Peste'))return ['score_adjustment'=>0,'explanation'=>null];
        $heavyRain=$weather->precipitation_mm!==null&&$weather->precipitation_mm>=5;
        $strongWind=$weather->wind_speed_kmh!==null&&$weather->wind_speed_kmh>=30;
        if($heavyRain&&$strongWind)return ['score_adjustment'=>-3,'explanation'=>'Ploaia puternică și vântul puternic reduc ușor încrederea pentru selecția de peste goluri. Vremea este doar un factor secundar.'];
        return ['score_adjustment'=>0,'explanation'=>null];
    }
}
