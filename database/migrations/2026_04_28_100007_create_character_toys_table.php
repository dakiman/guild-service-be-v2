<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_toys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('toy_id');
            $table->string('name', 200);
            $table->timestamps();

            $table->unique(['character_id', 'toy_id'], 'character_toys_unique');
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_toys');
    }
};
