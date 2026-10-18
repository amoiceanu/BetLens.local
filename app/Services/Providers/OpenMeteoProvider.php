<?php

namespace App\Services\Providers;

use App\Contracts\WeatherDataProviderInterface;
use App\Models\FootballMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenMeteoProvider implements WeatherDataProviderInterface
{
    private const HOURLY='temperature_2m,precipitation,precipitation_probability,wind_speed_10m,wind_direction_10m,weather_code';

    public function verifyConnection(): array
    {
        $response=Http::acceptJson()->timeout(20)->retry(3,500,throw:false)->get(config('services.open_meteo.forecast_url'),['latitude'=>44.4268,'longitude'=>26.1025,'hourly'=>'temperature_2m','forecast_days'=>1,'timezone'=>'auto']);
        if(!$response->successful())throw new RuntimeException('Open-Meteo a răspuns cu HTTP '.$response->status().'.');
        return ['records'=>[['provider'=>'open-meteo','timezone'=>$response->json('timezone'),'latitude'=>$response->json('latitude'),'longitude'=>$response->json('longitude')]],'message'=>'Conexiune Open-Meteo confirmată; prognoza orară este disponibilă.'];
    }

    public function weatherForMatch(FootballMatch $match): array
    {
        $latitude=$match->venue_latitude??$match->homeTeam?->latitude;
        $longitude=$match->venue_longitude??$match->homeTeam?->longitude;
        if($latitude===null||$longitude===null)return $this->unavailable('Coordonatele stadionului sau orașului gazdă sunt indisponibile.');
        $timezone=$match->venue_timezone??$match->homeTeam?->timezone??config('app.timezone');
        $kickoff=$match->kickoff_at->copy()->timezone($timezone);
        $historical=$kickoff->lt(now()->subDays(5));
        $url=$historical?config('services.open_meteo.archive_url'):config('services.open_meteo.forecast_url');
        $query=['latitude'=>$latitude,'longitude'=>$longitude,'hourly'=>self::HOURLY,'timezone'=>$timezone,'start_date'=>$kickoff->toDateString(),'end_date'=>$kickoff->toDateString()];
        $response=Http::acceptJson()->timeout(20)->retry(3,500,throw:false)->get($url,$query);
        if($response->status()===429)throw new RuntimeException('Limita de cereri Open-Meteo a fost atinsă.');
        if(!$response->successful())return $this->unavailable('Prognoza nu este disponibilă pentru data meciului.', ['http_status'=>$response->status()]);
        $payload=$response->json();$times=data_get($payload,'hourly.time',[]);
        if(!$times)return $this->unavailable('Open-Meteo nu a returnat date orare pentru acest meci.',$payload);
        $target=$kickoff->timestamp;$bestIndex=null;$bestDistance=PHP_INT_MAX;
        foreach($times as $index=>$time){$distance=abs(Carbon::parse($time,$timezone)->timestamp-$target);if($distance<$bestDistance){$bestDistance=$distance;$bestIndex=$index;}}
        if($bestIndex===null)return $this->unavailable('Ora meciului nu a putut fi asociată prognozei.',$payload);
        return ['status'=>'available','forecast_for'=>Carbon::parse($times[$bestIndex],$timezone)->utc(),'temperature_c'=>$this->value($payload,'temperature_2m',$bestIndex),'precipitation_mm'=>$this->value($payload,'precipitation',$bestIndex),'precipitation_probability'=>$this->value($payload,'precipitation_probability',$bestIndex),'wind_speed_kmh'=>$this->value($payload,'wind_speed_10m',$bestIndex),'wind_direction'=>$this->value($payload,'wind_direction_10m',$bestIndex),'weather_code'=>$this->value($payload,'weather_code',$bestIndex),'raw_payload'=>config('services.open_meteo.store_payloads')?$payload:null,'message'=>'Prognoză disponibilă.','coordinates'=>['latitude'=>(float)$latitude,'longitude'=>(float)$longitude,'timezone'=>$timezone]];
    }

    private function value(array $payload,string $key,int $index): mixed { return data_get($payload,"hourly.$key.$index"); }
    private function unavailable(string $message,?array $payload=null): array { return ['status'=>'unavailable','forecast_for'=>null,'temperature_c'=>null,'precipitation_mm'=>null,'precipitation_probability'=>null,'wind_speed_kmh'=>null,'wind_direction'=>null,'weather_code'=>null,'raw_payload'=>config('services.open_meteo.store_payloads')?$payload:null,'message'=>$message]; }
}
