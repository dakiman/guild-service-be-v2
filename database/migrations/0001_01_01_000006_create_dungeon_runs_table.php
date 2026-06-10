<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dungeon_runs', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('season');
            $table->integer('dungeon_id');
            $table->string('dungeon_name');
            $table->smallInteger('keystone_level');
            $table->bigInteger('duration');
            $table->bigInteger('completed_timestamp');
            $table->boolean('is_completed_on_time')->default(false);
            $table->jsonb('affixes')->default('[]');
            $table->timestamps();

            $table->unique(['season', 'dungeon_id', 'completed_timestamp', 'duration'], 'uq_dungeon_run');
            $table->index('season');
            $table->index(['season', 'keystone_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dungeon_runs');
    }
};
