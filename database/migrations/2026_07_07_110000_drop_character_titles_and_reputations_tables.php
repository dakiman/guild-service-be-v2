<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('character_titles');
        Schema::dropIfExists('character_reputations');
    }

    public function down(): void
    {
        // Data is not recoverable; recreate empty shells so down() is runnable.
        Schema::create('character_titles', function (Blueprint $table) {
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('title_id');
            $table->foreign('title_id')->references('id')->on('game_data_titles')->cascadeOnDelete();
            $table->primary(['character_id', 'title_id']);
        });

        Schema::create('character_reputations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('faction_id');
            $table->string('faction_name', 150);
            $table->string('standing', 20);
            $table->integer('value')->default(0);
            $table->integer('max')->default(0);
            $table->timestamps();
            $table->unique(['character_id', 'faction_id'], 'character_reputations_unique');
            $table->index('character_id');
        });
    }
};
