<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_seasons', function (Blueprint $table) {
            // PK = Blizzard's integer mythic-keystone season id — the same
            // value stored in dungeon_runs.season, assigned manually (never
            // auto-increment; ids come from Blizzard's season index).
            $table->unsignedSmallInteger('id')->primary();
            // Doubles as the raider.io season slug and the archive URL segment.
            $table->string('slug', 100)->unique();
            $table->string('name', 100);
            $table->string('raiderio_tier_slug', 100);
            // raider.io's expansion_id, consumed by dungeons:backfill-icons-from-raiderio.
            $table->unsignedSmallInteger('raiderio_expansion_id');
            $table->unsignedSmallInteger('expansion_id')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('expansion_id')
                ->references('id')->on('game_data_expansions')
                ->nullOnDelete();
        });

        // At most one current season, enforced at the DB level. Partial
        // indexes work on both PostgreSQL and SQLite (tests).
        DB::statement('CREATE UNIQUE INDEX uq_game_data_seasons_current ON game_data_seasons (is_current) WHERE is_current');
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_seasons');
    }
};
