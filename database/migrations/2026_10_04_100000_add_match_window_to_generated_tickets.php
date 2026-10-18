<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_tickets', function (Blueprint $table) {
            $table->dateTime('first_match_at')->nullable()->after('status');
            $table->dateTime('last_match_at')->nullable()->after('first_match_at');
        });
    }

    public function down(): void
    {
        Schema::table('generated_tickets', function (Blueprint $table) {
            $table->dropColumn(['first_match_at', 'last_match_at']);
        });
    }
};
