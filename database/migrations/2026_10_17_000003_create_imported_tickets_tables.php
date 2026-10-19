<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('imported_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('reference');
            $table->dateTime('placed_at');
            $table->string('status');
            $table->decimal('total_odds', 10, 2);
            $table->decimal('stake', 10, 2);
            $table->decimal('payout', 10, 2)->nullable();
            $table->string('currency', 3)->default('RON');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['source', 'reference']);
        });
        Schema::create('imported_ticket_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imported_ticket_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('competition');
            $table->dateTime('kickoff_at');
            $table->string('home_team');
            $table->string('away_team');
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->string('market_label');
            $table->string('selection_label');
            $table->decimal('odds', 10, 2)->nullable();
            $table->string('result');
            $table->text('failure_reason')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['imported_ticket_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_ticket_selections');
        Schema::dropIfExists('imported_tickets');
    }
};
