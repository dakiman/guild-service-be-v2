<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('character_titles');

        Schema::create('character_titles', function (Blueprint $table) {
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('title_id');
            $table->foreign('title_id')->references('id')->on('game_data_titles')->cascadeOnDelete();
            $table->primary(['character_id', 'title_id']);
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('active_title_id')->nullable()->after('titles_synced_at');
            $table->foreign('active_title_id')->references('id')->on('game_data_titles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropForeign(['active_title_id']);
            $table->dropColumn('active_title_id');
        });

        Schema::dropIfExists('character_titles');

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
    }
};
