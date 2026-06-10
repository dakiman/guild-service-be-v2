<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guild_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('realm');
            $table->smallInteger('level');
            $table->smallInteger('class_id');
            $table->smallInteger('race_id');
            $table->smallInteger('rank');
            $table->timestamps();

            $table->unique(['guild_id', 'name', 'realm']);
            $table->index('guild_id');
            $table->index('rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guild_members');
    }
};
