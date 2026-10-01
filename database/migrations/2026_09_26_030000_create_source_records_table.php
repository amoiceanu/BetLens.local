<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('source_records',function(Blueprint $t){$t->id();$t->foreignId('data_source_id')->constrained()->cascadeOnDelete();$t->string('external_key',191);$t->string('record_type',80)->default('external_record');$t->json('payload');$t->string('checksum',64);$t->dateTime('first_seen_at');$t->dateTime('last_seen_at');$t->timestamps();$t->unique(['data_source_id','external_key']);$t->index(['data_source_id','record_type']);}); }
    public function down(): void { Schema::dropIfExists('source_records'); }
};
