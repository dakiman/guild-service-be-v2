<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_titles', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name_male', 255);
            $table->string('name_female', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_titles');
    }
};
