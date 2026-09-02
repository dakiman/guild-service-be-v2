<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_ranks', function (Blueprint $table) {
            $table->foreignId('character_id')->primary()->constrained('characters')->cascadeOnDelete();
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

            $table->index('world_rank', 'character_ranks_world_idx');
            $table->index(['region', 'region_rank'], 'character_ranks_region_idx');
            $table->index(['region', 'connected_realm_id', 'realm_rank'], 'character_ranks_realm_idx');
            $table->index(['region', 'class_id', 'class_rank'], 'character_ranks_class_idx');
            $table->index(['region', 'spec_id', 'spec_rank'], 'character_ranks_spec_idx');
        });

        Schema::create('realm_run_boards', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('period_id');
            $table->string('region', 4);
            $table->unsignedInteger('connected_realm_id');
            $table->jsonb('payload');
            $table->timestampTz('computed_at');
            $table->timestamps();

            $table->unique(['period_id', 'region', 'connected_realm_id'], 'realm_run_boards_shard_uq');
        });

        // Scratch map rebuilt by RealmSlugMapBuilder on every materialization so
        // the ranking SQL is plain joins (portable to SQLite — no jsonb functions).
        Schema::create('realm_slug_map', function (Blueprint $table) {
            $table->id();
            $table->string('region', 4);
            $table->string('realm_slug');
            $table->unsignedInteger('connected_realm_id');

            $table->unique(['region', 'realm_slug'], 'realm_slug_map_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realm_slug_map');
        Schema::dropIfExists('realm_run_boards');
        Schema::dropIfExists('character_ranks');
    }
};
