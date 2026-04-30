<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_mounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('mount_id');
            $table->string('name', 200);
            $table->boolean('is_useable')->default(true);
            $table->timestamps();

            $table->unique(['character_id', 'mount_id'], 'character_mounts_unique');
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_mounts');
    }
};
