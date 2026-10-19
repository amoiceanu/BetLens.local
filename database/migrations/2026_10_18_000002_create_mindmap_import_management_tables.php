<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('import_method', 32)->default('existing');
            $table->boolean('import_enabled')->default(true);
            $table->string('import_frequency', 20)->default('manual');
            $table->unsignedTinyInteger('request_timeout')->default(20);
        });

        Schema::create('data_source_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->timestamps();
            $table->unique(['data_source_id', 'level']);
            $table->index('level');
        });

        Schema::create('data_source_competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->string('external_competition_id')->nullable();
            $table->string('coverage_status', 20)->default('unverified');
            $table->text('notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['data_source_id', 'league_id']);
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('league_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('queued');
            $table->string('execution_source', 32);
            $table->string('import_method', 32);
            $table->unsignedInteger('records_fetched')->default(0);
            $table->unsignedInteger('records_new')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('records_errors')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['data_source_id', 'status']);
            $table->index(['created_at', 'status']);
        });

        Schema::create('import_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('content_type', 100)->nullable();
            $table->string('checksum', 64);
            $table->unsignedBigInteger('bytes');
            $table->timestamps();
        });

        Schema::create('source_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('external_field', 160);
            $table->string('internal_field', 160);
            $table->timestamps();
            $table->unique(['data_source_id', 'external_field']);
        });

        $levels = [
            'api-football' => [1, 3], 'football-data' => [1], 'openligadb' => [1],
            'statsbomb-open-data' => [1, 2], 'football-data-uk' => [1, 2],
            'sportmonks' => [2, 3], 'understat' => [2], 'the-odds-api' => [2],
            'odds-api-io-superbet' => [2], 'oddspapi-superbet' => [2], 'open-meteo' => [5],
        ];
        foreach (DB::table('data_sources')->whereIn('slug', array_keys($levels))->get(['id', 'slug']) as $source) {
            foreach ($levels[$source->slug] as $level) {
                DB::table('data_source_levels')->insert(['data_source_id' => $source->id, 'level' => $level, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach ([
            ['name' => 'GSP.ro · candidat', 'slug' => 'gsp-candidate', 'level' => 4, 'website_url' => 'https://www.gsp.ro/'],
            ['name' => 'Digi Sport · candidat', 'slug' => 'digisport-candidate', 'level' => 4, 'website_url' => 'https://www.digisport.ro/'],
            ['name' => 'BetLens · model intern', 'slug' => 'betlens-model', 'level' => 6, 'website_url' => null],
        ] as $candidate) {
            $id = DB::table('data_sources')->insertGetId([
                'name' => $candidate['name'], 'slug' => $candidate['slug'], 'category' => $candidate['level'] === 6 ? 'Recalculare internă' : 'Știri externe · adaptor neimplementat',
                'description' => $candidate['level'] === 6 ? 'Procesor intern BetLens; nu este API extern.' : 'Sursă candidată fără adaptor autorizat; importul automat este dezactivat.',
                'website_url' => $candidate['website_url'], 'verification_url' => null,
                'status' => 'not_configured', 'active' => $candidate['level'] === 6,
                'import_method' => $candidate['level'] === 6 ? 'internal_model' : 'unsupported',
                'import_enabled' => $candidate['level'] === 6, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('data_source_levels')->insert(['data_source_id' => $id, 'level' => $candidate['level'], 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('source_field_mappings');
        Schema::dropIfExists('import_documents');
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('data_source_competitions');
        Schema::dropIfExists('data_source_levels');
        DB::table('data_sources')->whereIn('slug', ['gsp-candidate', 'digisport-candidate', 'betlens-model'])->delete();
        Schema::table('data_sources', fn (Blueprint $table) => $table->dropColumn(['import_method', 'import_enabled', 'import_frequency', 'request_timeout']));
    }
};
