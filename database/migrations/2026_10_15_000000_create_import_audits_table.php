<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_audits', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('import_type');
            $table->string('status');
            $table->dateTime('imported_at');
            $table->unsignedInteger('records_new')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_deleted')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['provider', 'imported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_audits');
    }
};
