<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_pvp_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('bracket', 32);
            $table->smallInteger('rating')->default(0);
            $table->integer('season_won')->default(0);
            $table->integer('season_lost')->default(0);
            $table->integer('season_played')->default(0);
            $table->integer('weekly_won')->default(0);
            $table->integer('weekly_lost')->default(0);
            $table->integer('weekly_played')->default(0);
            $table->string('tier_name', 50)->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'bracket'], 'character_pvp_brackets_unique');
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_pvp_brackets');
    }
};
