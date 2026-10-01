<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if(!Schema::hasTable('data_sources')) Schema::create('data_sources',function(Blueprint $t){
            $t->id(); $t->string('name'); $t->string('slug')->unique(); $t->string('category'); $t->text('description'); $t->string('website_url')->nullable(); $t->string('verification_url')->nullable(); $t->string('credential_env')->nullable(); $t->string('status')->default('never_checked'); $t->timestamp('last_checked_at')->nullable(); $t->unsignedInteger('last_duration_ms')->nullable(); $t->unsignedInteger('records_checked')->default(0); $t->text('last_message')->nullable(); $t->boolean('active')->default(true); $t->timestamps();
        });
        $now=now();
        $sources=[
            ['name'=>'football-data.org','slug'=>'football-data','category'=>'Competiții, echipe, meciuri și rezultate','description'=>'Adaptor pregătit pentru calendar, clasamente și rezultate. Necesită token API.','website_url'=>'https://www.football-data.org/','verification_url'=>'https://api.football-data.org/v4/competitions','credential_env'=>'FOOTBALL_DATA_API_KEY','status'=>'not_configured','active'=>true,'created_at'=>$now,'updated_at'=>$now],
            ['name'=>'API-Football','slug'=>'api-football','category'=>'Meciuri, statistici, accidentări și loturi','description'=>'Sursă opțională pentru acoperire extinsă și informații despre lot. Necesită cheie API.','website_url'=>'https://www.api-football.com/','verification_url'=>'https://v3.football.api-sports.io/status','credential_env'=>'API_FOOTBALL_KEY','status'=>'not_configured','active'=>true,'created_at'=>$now,'updated_at'=>$now],
            ['name'=>'The Odds API','slug'=>'the-odds-api','category'=>'Cote și piețe','description'=>'Adaptor opțional pentru cote curente și istoricul variațiilor. Necesită cheie API.','website_url'=>'https://the-odds-api.com/','verification_url'=>'https://api.the-odds-api.com/v4/sports/','credential_env'=>'ODDS_API_KEY','status'=>'not_configured','active'=>true,'created_at'=>$now,'updated_at'=>$now],
            ['name'=>'OpenLigaDB','slug'=>'openligadb','category'=>'Competiții, meciuri și rezultate','description'=>'API public pentru verificări suplimentare de calendare și rezultate.','website_url'=>'https://www.openligadb.de/','verification_url'=>'https://api.openligadb.de/getavailableleagues','status'=>'never_checked','active'=>true,'created_at'=>$now,'updated_at'=>$now],
            ['name'=>'Understat','slug'=>'understat','category'=>'xG, xGA și statistici avansate','description'=>'Sursă publică auxiliară pentru validarea disponibilității datelor xG.','website_url'=>'https://understat.com/','verification_url'=>'https://understat.com/league/EPL','status'=>'never_checked','active'=>true,'created_at'=>$now,'updated_at'=>$now],
        ];
        $defaults=['website_url'=>null,'verification_url'=>null,'credential_env'=>null,'status'=>'never_checked','last_checked_at'=>null,'last_duration_ms'=>null,'records_checked'=>0,'last_message'=>null,'active'=>true,'created_at'=>$now,'updated_at'=>$now];
        DB::table('data_sources')->insert(array_map(fn(array $source)=>array_merge($defaults,$source),$sources));
    }
    public function down(): void { Schema::dropIfExists('data_sources'); }
};
