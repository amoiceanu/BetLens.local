<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now=now();
        DB::table('data_sources')->upsert([
            ['name'=>'Sportmonks Football API','slug'=>'sportmonks','category'=>'Statistici avansate, loturi, absențe și xG','description'=>'Sursă secundară. Completează datele API-Football fără să suprascrie identitatea meciului.','website_url'=>'https://www.sportmonks.com/football-api/','verification_url'=>'https://api.sportmonks.com/v3/football/leagues','credential_env'=>'SPORTMONKS_API_TOKEN','status'=>'not_configured','active'=>true,'created_at'=>$now,'updated_at'=>$now],
            ['name'=>'Open-Meteo API','slug'=>'open-meteo','category'=>'Prognoză și istoric meteo pentru meciuri','description'=>'Context meteo la ora locală a meciului. Nu necesită cheie API.','website_url'=>'https://open-meteo.com/','verification_url'=>'https://api.open-meteo.com/v1/forecast','credential_env'=>null,'status'=>'never_checked','active'=>true,'created_at'=>$now,'updated_at'=>$now],
        ],['slug'],['name','category','description','website_url','verification_url','credential_env','active','updated_at']);
    }

    public function down(): void { DB::table('data_sources')->whereIn('slug',['sportmonks','open-meteo'])->delete(); }
};
