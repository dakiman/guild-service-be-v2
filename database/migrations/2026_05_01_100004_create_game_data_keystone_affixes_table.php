<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_keystone_affixes', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // Blizzard keystone-affix id
            $table->string('name', 100);
            $table->text('icon_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_keystone_affixes');
    }
};
