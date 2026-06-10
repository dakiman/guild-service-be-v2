<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raid_encounter_kills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('expansion_name', 100);
            $table->integer('instance_id');
            $table->string('instance_name', 150);
            $table->integer('encounter_id');
            $table->string('encounter_name', 150);
            $table->string('difficulty', 16);
            $table->integer('completed_count')->default(0);
            $table->bigInteger('last_kill_timestamp')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'encounter_id', 'difficulty'], 'raid_encounter_kills_unique');
            $table->index(['character_id', 'difficulty']);
            $table->index(['instance_id', 'difficulty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raid_encounter_kills');
    }
};
