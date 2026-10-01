<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('data_sources',function(Blueprint $t){$t->unsignedInteger('last_database_records')->default(0);$t->unsignedInteger('last_new_records')->default(0);$t->unsignedInteger('last_updated_records')->default(0);$t->unsignedInteger('last_unchanged_records')->default(0);$t->unsignedInteger('last_error_records')->default(0);}); }
    public function down(): void { Schema::table('data_sources',function(Blueprint $t){$t->dropColumn(['last_database_records','last_new_records','last_updated_records','last_unchanged_records','last_error_records']);}); }
};
