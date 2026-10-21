<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('data_sources') || ! Schema::hasTable('data_source_levels')) {
            return;
        }

        $unsupportedSecondaryRoles = [
            'api-football' => 3,
            'statsbomb-open-data' => 2,
        ];

        foreach ($unsupportedSecondaryRoles as $slug => $level) {
            $sourceId = DB::table('data_sources')->where('slug', $slug)->value('id');
            if ($sourceId) {
                DB::table('data_source_levels')->where('data_source_id', $sourceId)->where('level', $level)->delete();
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('data_sources') || ! Schema::hasTable('data_source_levels')) {
            return;
        }

        foreach (['api-football' => 3, 'statsbomb-open-data' => 2] as $slug => $level) {
            $sourceId = DB::table('data_sources')->where('slug', $slug)->value('id');
            if ($sourceId && ! DB::table('data_source_levels')->where('data_source_id', $sourceId)->where('level', $level)->exists()) {
                DB::table('data_source_levels')->insert(['data_source_id' => $sourceId, 'level' => $level, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
};
