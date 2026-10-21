<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schema = config('database.default') === 'mysql' && config('database.connections.mysql.prefix') === 'st_' ? Schema::connection('mysql_auth') : Schema::getFacadeRoot();
        $schema->create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        $schema->create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = config('database.default') === 'mysql' && config('database.connections.mysql.prefix') === 'st_' ? Schema::connection('mysql_auth') : Schema::getFacadeRoot();
        $schema->dropIfExists('cache');
        $schema->dropIfExists('cache_locks');
    }
};
