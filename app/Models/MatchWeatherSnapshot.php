<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MatchWeatherSnapshot extends Model { protected $guarded=[]; protected $casts=['forecast_for'=>'datetime','fetched_at'=>'datetime','temperature_c'=>'float','precipitation_mm'=>'float','wind_speed_kmh'=>'float','raw_payload'=>'array']; public function match(){return $this->belongsTo(FootballMatch::class,'match_id');} public function getConditionLabelAttribute(): string { $code=$this->weather_code; if($code===null)return 'Indisponibil'; return match(true){$code===0=>'Senin',$code<=3=>'Parțial noros',$code<=48=>'Ceață',$code<=57=>'Burniță',$code<=67=>'Ploaie',$code<=77=>'Ninsoare',$code<=82=>'Averse',$code<=86=>'Averse de ninsoare',$code<=99=>'Furtună',default=>'Indisponibil'}; } }
