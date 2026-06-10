<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some achievements reference category IDs from Blizzard's `root_categories`
 * or `guild_categories` lists, which the category-index mapper does not
 * currently extract — those parent-categories never get synced and their
 * FK references blow up the achievement upsert. Drop the FK and keep
 * category_id as a plain index column. Same philosophy as
 * game_data_factions.parent_faction_id and (after Plan 5)
 * game_data_achievement_categories.parent_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_data_achievements', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });
    }

    public function down(): void
    {
        Schema::table('game_data_achievements', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('game_data_achievement_categories')
                ->nullOnDelete();
        });
    }
};
