<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('achievement_id');
            $table->bigInteger('completed_timestamp')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'achievement_id'], 'character_achievements_unique');
            $table->index(['character_id', 'completed_timestamp'], 'character_achievements_recency_idx');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('achievements_synced_at')->nullable()->after('collections_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('achievements_synced_at');
        });

        Schema::dropIfExists('character_achievements');
    }
};
