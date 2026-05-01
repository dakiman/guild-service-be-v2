<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_raid_encounters', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // Blizzard journal-encounter id
            $table->unsignedInteger('raid_instance_id');
            $table->string('name', 200);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->unsignedInteger('creature_display_id')->nullable();
            $table->text('portrait_url')->nullable();
            $table->timestamps();

            $table->foreign('raid_instance_id')
                ->references('id')
                ->on('game_data_raid_instances')
                ->cascadeOnDelete();

            $table->index('raid_instance_id');
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_raid_encounters');
    }
};
