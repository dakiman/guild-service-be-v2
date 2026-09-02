<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * character_ranks is rebuilt nightly, so both directions drop and recreate
 * it instead of migrating rows. Up: one row per (character, season) so a
 * rollover freezes the outgoing season's standings in place. Down: the
 * Rank Spine shape (PK character_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('character_ranks');

        Schema::create('character_ranks', function (Blueprint $table) {
            $this->columns($table);
            $table->primary(['character_id', 'season_id'], 'character_ranks_pkey');

            $table->index(['season_id', 'world_rank'], 'character_ranks_world_idx');
            $table->index(['season_id', 'region', 'region_rank'], 'character_ranks_region_idx');
            $table->index(['season_id', 'region', 'connected_realm_id', 'realm_rank'], 'character_ranks_realm_idx');
            $table->index(['season_id', 'region', 'class_id', 'class_rank'], 'character_ranks_class_idx');
            $table->index(['season_id', 'region', 'spec_id', 'spec_rank'], 'character_ranks_spec_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_ranks');

        Schema::create('character_ranks', function (Blueprint $table) {
            $this->columns($table);
            $table->primary('character_id', 'character_ranks_pkey');

            $table->index('world_rank', 'character_ranks_world_idx');
            $table->index(['region', 'region_rank'], 'character_ranks_region_idx');
            $table->index(['region', 'connected_realm_id', 'realm_rank'], 'character_ranks_realm_idx');
            $table->index(['region', 'class_id', 'class_rank'], 'character_ranks_class_idx');
            $table->index(['region', 'spec_id', 'spec_rank'], 'character_ranks_spec_idx');
        });
    }

    private function columns(Blueprint $table): void
    {
        $table->foreignId('character_id')->constrained('characters')->cascadeOnDelete();
        $table->unsignedSmallInteger('season_id');
        $table->string('region', 2);
        $table->unsignedInteger('connected_realm_id')->nullable();
        $table->unsignedSmallInteger('class_id');
        $table->unsignedInteger('spec_id')->nullable();
        $table->smallInteger('rating');
        $table->unsignedInteger('world_rank');
        $table->unsignedInteger('region_rank');
        $table->unsignedInteger('realm_rank')->nullable();
        $table->unsignedInteger('class_rank');
        $table->unsignedInteger('spec_rank')->nullable();
        $table->unsignedInteger('world_pop');
        $table->unsignedInteger('region_pop');
        $table->unsignedInteger('realm_pop')->nullable();
        $table->unsignedInteger('class_pop');
        $table->unsignedInteger('spec_pop')->nullable();
        $table->timestampTz('computed_at');
    }
};
