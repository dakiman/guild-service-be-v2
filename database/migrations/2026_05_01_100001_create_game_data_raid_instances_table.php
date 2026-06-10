<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_raid_instances', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // Blizzard journal-instance id
            $table->string('name', 200);
            $table->unsignedSmallInteger('expansion_id')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->text('media_url')->nullable();
            $table->timestamps();

            $table->foreign('expansion_id')
                ->references('id')
                ->on('game_data_expansions')
                ->nullOnDelete();

            $table->index('expansion_id');
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_raid_instances');
    }
};
