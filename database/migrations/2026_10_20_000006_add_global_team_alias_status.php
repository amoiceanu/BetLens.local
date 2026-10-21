<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('team_aliases', 'status')) {
            Schema::table('team_aliases', function (Blueprint $table) {
                $table->string('status', 20)->default('active');
                $table->index(['status', 'normalized_alias'], 'team_alias_status_normalized_index');
            });
        }

        // Preserve every historical row. Mark ambiguous normalized names for manual review;
        // a global unique constraint can be added only after these conflicts are resolved.
        $conflicts = DB::table('team_aliases')
            ->select('normalized_alias')
            ->groupBy('normalized_alias')
            ->havingRaw('COUNT(DISTINCT team_id) > 1')
            ->pluck('normalized_alias');

        foreach ($conflicts->chunk(500) as $chunk) {
            DB::table('team_aliases')->whereIn('normalized_alias', $chunk)->update(['status' => 'review']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('team_aliases', 'status')) {
            Schema::table('team_aliases', function (Blueprint $table) {
                $table->dropIndex('team_alias_status_normalized_index');
                $table->dropColumn('status');
            });
        }
    }
};
