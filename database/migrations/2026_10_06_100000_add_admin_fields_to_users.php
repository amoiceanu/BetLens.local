<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { (config('database.default')==='mysql'&&config('database.connections.mysql.prefix')==='st_'?Schema::connection('mysql_auth'):Schema::getFacadeRoot())->table('users',function(Blueprint $table){$table->string('username')->nullable()->unique()->after('name');$table->boolean('is_admin')->default(false)->after('password');}); }
    public function down(): void { (config('database.default')==='mysql'&&config('database.connections.mysql.prefix')==='st_'?Schema::connection('mysql_auth'):Schema::getFacadeRoot())->table('users',fn(Blueprint $table)=>$table->dropColumn(['username','is_admin'])); }
};
