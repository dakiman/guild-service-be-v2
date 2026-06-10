<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('title_id');
            $table->string('name', 150);
            $table->string('display_string', 255);
            $table->boolean('is_selected')->default(false);
            $table->timestamps();

            $table->unique(['character_id', 'title_id'], 'character_titles_unique');
            $table->index('character_id');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->timestamp('titles_synced_at')->nullable()->after('stats_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('titles_synced_at');
        });

        Schema::dropIfExists('character_titles');
    }
};
