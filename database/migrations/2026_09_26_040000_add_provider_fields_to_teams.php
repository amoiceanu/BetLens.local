<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('teams',function(Blueprint $t){$t->string('provider',40)->nullable()->after('crest');$t->string('external_id',100)->nullable()->after('provider');$t->unique(['league_id','provider','external_id'],'teams_provider_external_unique');}); }
    public function down(): void { Schema::table('teams',function(Blueprint $t){$t->dropUnique('teams_provider_external_unique');$t->dropColumn(['provider','external_id']);}); }
};
