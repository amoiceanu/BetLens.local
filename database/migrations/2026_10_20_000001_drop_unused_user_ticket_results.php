<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ticket outcomes are stored on generated_tickets; this legacy side table has no runtime readers/writers.
        Schema::dropIfExists('user_ticket_results');
    }

    public function down(): void
    {
        Schema::create('user_ticket_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->decimal('return_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
