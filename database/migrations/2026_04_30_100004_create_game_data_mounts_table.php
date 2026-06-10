<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_mounts', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('source_text', 255)->nullable();
            $table->unsignedInteger('summon_spell_id')->nullable();
            $table->unsignedInteger('item_id')->nullable();
            $table->timestamps();

            $table->index('summon_spell_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_mounts');
    }
};
