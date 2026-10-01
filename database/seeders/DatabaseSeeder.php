<?php
namespace Database\Seeders;
use App\Models\ApplicationSetting;
use App\Models\League;
use App\Models\Market;
use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach([
            ['name'=>'Premier League','slug'=>'premier-league','country'=>'Anglia','code'=>'PL','accent'=>'#8b5cf6'],
            ['name'=>'La Liga','slug'=>'la-liga','country'=>'Spania','code'=>'LL','accent'=>'#fb7185'],
            ['name'=>'Serie A','slug'=>'serie-a','country'=>'Italia','code'=>'SA','accent'=>'#60a5fa'],
            ['name'=>'Bundesliga','slug'=>'bundesliga','country'=>'Germania','code'=>'BL','accent'=>'#f87171'],
            ['name'=>'Ligue 1','slug'=>'ligue-1','country'=>'Franța','code'=>'L1','accent'=>'#38bdf8'],
            ['name'=>'UEFA Champions League','slug'=>'champions-league','country'=>'Europa','code'=>'UCL','accent'=>'#a78bfa'],
            ['name'=>'SuperLiga România','slug'=>'superliga-romania','country'=>'România','code'=>'SLR','accent'=>'#fbbf24'],
        ] as $league) League::updateOrCreate(['slug'=>$league['slug']],$league+['active'=>true]);
        foreach([['key'=>'over_15','name'=>'Peste/Sub 1.5 goluri'],['key'=>'over_25','name'=>'Peste/Sub 2.5 goluri'],['key'=>'btts','name'=>'Ambele echipe marchează'],['key'=>'double_chance','name'=>'Șansă dublă'],['key'=>'result','name'=>'1X2'],['key'=>'under_35','name'=>'Peste/Sub 3.5 goluri']] as $market) Market::updateOrCreate(['key'=>$market['key']],$market+['active'=>true]);
        foreach(['conservator'=>['min_probability'=>.68,'min_value'=>.02,'min_confidence'=>70],'echilibrat'=>['min_probability'=>.62,'min_value'=>.015,'min_confidence'=>62],'agresiv'=>['min_probability'=>.56,'min_value'=>.01,'min_confidence'=>55]] as $key=>$value) ApplicationSetting::updateOrCreate(['key'=>'risk.'.$key],['value'=>$value,'group'=>'risk']);
    }
}
