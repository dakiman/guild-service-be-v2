<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blizzard's /data/wow/achievement-category/index returns child categories
 * before their parents, so an FK on parent_id rejects half the inserts.
 * Drop the constraint and keep parent_id as a plain index column — same
 * pattern as game_data_factions.parent_faction_id (Plan 5 factions slice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_data_achievement_categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('game_data_achievement_categories', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('game_data_achievement_categories')
                ->nullOnDelete();
        });
    }
};
