<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_achievement_categories', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 150);
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index('parent_id');
            $table->index('display_order');
        });

        // Add self-referencing foreign key after table creation
        Schema::table('game_data_achievement_categories', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('game_data_achievement_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_achievement_categories');
    }
};
