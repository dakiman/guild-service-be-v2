<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ladder_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('period_id');
            $table->string('region', 4);
            $table->unsignedInteger('dungeon_id');
            $table->unsignedSmallInteger('keystone_level');
            $table->unsignedInteger('duration');
            $table->unsignedBigInteger('completed_timestamp');
            $table->boolean('is_completed_on_time')->default(false);
            $table->jsonb('affixes')->default('[]');
            $table->string('comp_signature', 60)->nullable();
            $table->string('run_hash', 40)->unique();
            $table->timestamps();

            $table->index(['period_id', 'region', 'dungeon_id'], 'ladder_runs_shard_idx');
            $table->index(['period_id', 'region', 'comp_signature'], 'ladder_runs_comp_idx');
        });

        Schema::create('ladder_run_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ladder_run_id')->constrained('ladder_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->string('name');
            $table->string('realm_slug')->nullable();
            $table->unsignedInteger('realm_id')->nullable();
            $table->string('faction', 8)->nullable();
            $table->unsignedSmallInteger('spec_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ladder_run_members');
        Schema::dropIfExists('ladder_runs');
    }
};
