<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_mythic_keystone_dungeons', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // Blizzard mythic-keystone dungeon id
            $table->string('name', 200);
            $table->text('media_url')->nullable();
            $table->unsignedInteger('journal_instance_id')->nullable();
            $table->timestamps();

            // Not FK-constrained: dungeons may reference a journal-instance that
            // is not tracked in game_data_raid_instances (older expansions whose
            // raids we don't sync). Treat as a soft join key.
            $table->index('journal_instance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_mythic_keystone_dungeons');
    }
};
