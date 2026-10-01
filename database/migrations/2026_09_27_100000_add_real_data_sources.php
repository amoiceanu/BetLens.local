<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now=now();
        $sources=[
            [
                'name'=>'StatsBomb Open Data',
                'slug'=>'statsbomb-open-data',
                'category'=>'Competiții, sezoane, meciuri și evenimente',
                'description'=>'Set oficial de date deschise StatsBomb pentru analiză fotbalistică și cercetare.',
                'website_url'=>'https://github.com/statsbomb/open-data',
                'verification_url'=>'https://raw.githubusercontent.com/statsbomb/open-data/master/data/competitions.json',
            ],
            [
                'name'=>'Football-Data.co.uk',
                'slug'=>'football-data-uk',
                'category'=>'Rezultate istorice, statistici și cote',
                'description'=>'Fișiere CSV reale cu rezultate, statistici de meci și cote istorice pentru ligile europene.',
                'website_url'=>'https://www.football-data.co.uk/',
                'verification_url'=>'https://www.football-data.co.uk/mmz4281/2627/E0.csv',
            ],
        ];

        foreach($sources as $source){
            DB::table('data_sources')->updateOrInsert(
                ['slug'=>$source['slug']],
                $source+[
                    'credential_env'=>null,
                    'status'=>'never_checked',
                    'last_checked_at'=>null,
                    'last_duration_ms'=>null,
                    'records_checked'=>0,
                    'last_message'=>null,
                    'active'=>true,
                    'updated_at'=>$now,
                    'created_at'=>$now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('data_sources')->whereIn('slug',['statsbomb-open-data','football-data-uk'])->delete();
    }
};
