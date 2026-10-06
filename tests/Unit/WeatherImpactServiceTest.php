<?php
namespace Tests\Unit;
use App\Models\MatchWeatherSnapshot;
use App\Services\WeatherImpactService;
use PHPUnit\Framework\TestCase;
class WeatherImpactServiceTest extends TestCase
{
    public function test_severe_weather_only_slightly_reduces_over_goals_confidence(): void
    {
        $weather=new MatchWeatherSnapshot(['precipitation_mm'=>8,'wind_speed_kmh'=>42]);
        $impact=(new WeatherImpactService)->assess($weather,'Peste 2.5');
        $this->assertSame(-3,$impact['score_adjustment']);
        $this->assertNotNull($impact['explanation']);
        $this->assertSame(0,(new WeatherImpactService)->assess($weather,'1X')['score_adjustment']);
    }
}
