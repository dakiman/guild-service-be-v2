<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_achievements', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedSmallInteger('points')->default(0);
            $table->boolean('is_account_wide')->default(false);
            $table->timestamps();

            $table->foreign('category_id')
                ->references('id')
                ->on('game_data_achievement_categories')
                ->nullOnDelete();

            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_achievements');
    }
};
