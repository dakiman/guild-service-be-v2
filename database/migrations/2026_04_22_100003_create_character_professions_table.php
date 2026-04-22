<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_professions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('profession_id');
            $table->string('profession_name', 100);
            $table->string('tier_name', 100);
            $table->smallInteger('skill_points')->default(0);
            $table->smallInteger('max_skill_points')->default(0);
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(['character_id', 'profession_id', 'tier_name'], 'character_professions_unique');
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_professions');
    }
};
