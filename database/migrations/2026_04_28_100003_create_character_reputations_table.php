<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_reputations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('faction_id');
            $table->string('faction_name', 150);
            $table->string('standing', 20);
            $table->integer('value')->default(0);
            $table->integer('max')->default(0);
            $table->timestamps();

            $table->unique(['character_id', 'faction_id'], 'character_reputations_unique');
            $table->index('character_id');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('reputations_synced_at')->nullable()->after('titles_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('reputations_synced_at');
        });

        Schema::dropIfExists('character_reputations');
    }
};
