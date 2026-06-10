<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('pet_id');
            $table->integer('species_id');
            $table->string('name', 200);
            $table->smallInteger('level')->default(1);
            $table->smallInteger('breed_id')->nullable();
            $table->string('quality', 20)->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->integer('creature_display_id')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'pet_id'], 'character_pets_unique');
            $table->index('character_id');
            $table->index(['character_id', 'species_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_pets');
    }
};
