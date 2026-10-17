<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('website_url')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::table('operators')->insert([
            ['name'=>'Winbet','slug'=>'winbet','website_url'=>'https://winbet.ro/','active'=>true,'created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Superbet','slug'=>'superbet','website_url'=>'https://superbet.ro/','active'=>true,'created_at'=>now(),'updated_at'=>now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
