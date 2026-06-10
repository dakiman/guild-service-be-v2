<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dungeon_run_members', function (Blueprint $table) {
            $table->index('character_id');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->index('guild_id');
            $table->index('user_id');
            $table->index('last_searched_at');
        });

        Schema::table('guild_members', function (Blueprint $table) {
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::table('dungeon_run_members', function (Blueprint $table) {
            $table->dropIndex(['character_id']);
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->dropIndex(['guild_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['last_searched_at']);
        });

        Schema::table('guild_members', function (Blueprint $table) {
            $table->dropIndex(['character_id']);
        });
    }
};
