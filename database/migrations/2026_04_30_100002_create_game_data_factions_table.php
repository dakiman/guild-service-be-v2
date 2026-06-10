<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_factions', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 150);
            $table->unsignedInteger('parent_faction_id')->nullable();
            $table->unsignedSmallInteger('expansion_id')->nullable();
            $table->timestamps();

            $table->foreign('expansion_id')
                ->references('id')
                ->on('game_data_expansions')
                ->nullOnDelete();

            $table->index('parent_faction_id');
            $table->index('expansion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_factions');
    }
};
