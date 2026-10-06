<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources',function(Blueprint $table){$table->json('metadata')->nullable()->after('last_message');});
        Schema::table('teams',function(Blueprint $table){$table->string('city')->nullable();$table->decimal('latitude',10,7)->nullable();$table->decimal('longitude',10,7)->nullable();$table->string('timezone')->nullable();});
        Schema::table('matches',function(Blueprint $table){$table->decimal('venue_latitude',10,7)->nullable();$table->decimal('venue_longitude',10,7)->nullable();$table->string('venue_city')->nullable();$table->string('venue_timezone')->nullable();$table->string('data_status')->default('resolved')->index();$table->json('conflict_details')->nullable();});

        Schema::create('external_id_mappings',function(Blueprint $table){
            $table->id();$table->string('provider');$table->string('entity_type');$table->unsignedBigInteger('internal_id');$table->string('external_id');$table->string('external_name')->nullable();$table->json('metadata')->nullable();$table->timestamp('last_verified_at')->nullable();$table->timestamps();
            $table->unique(['provider','entity_type','external_id']);$table->index(['entity_type','internal_id']);
        });

        Schema::create('match_weather_snapshots',function(Blueprint $table){
            $table->id();$table->foreignId('match_id')->constrained()->cascadeOnDelete();$table->string('provider');$table->dateTime('forecast_for');$table->dateTime('fetched_at');$table->decimal('temperature_c',5,2)->nullable();$table->decimal('precipitation_mm',7,2)->nullable();$table->unsignedTinyInteger('precipitation_probability')->nullable();$table->decimal('wind_speed_kmh',6,2)->nullable();$table->unsignedSmallInteger('wind_direction')->nullable();$table->unsignedSmallInteger('weather_code')->nullable();$table->json('raw_payload')->nullable();$table->timestamps();
            $table->index(['match_id','forecast_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_weather_snapshots');Schema::dropIfExists('external_id_mappings');
        Schema::table('matches',fn(Blueprint $table)=>$table->dropColumn(['venue_latitude','venue_longitude','venue_city','venue_timezone','data_status','conflict_details']));
        Schema::table('teams',fn(Blueprint $table)=>$table->dropColumn(['city','latitude','longitude','timezone']));
        Schema::table('data_sources',fn(Blueprint $table)=>$table->dropColumn('metadata'));
    }
};
