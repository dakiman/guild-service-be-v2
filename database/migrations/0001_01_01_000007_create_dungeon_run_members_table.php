<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dungeon_run_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dungeon_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->string('character_name');
            $table->string('character_realm');
            $table->string('character_region', 2);
            $table->integer('spec_id')->nullable();
            $table->string('spec_name', 100)->nullable();
            $table->smallInteger('equipped_item_level')->nullable();
            $table->timestamps();

            $table->unique(
                ['dungeon_run_id', 'character_name', 'character_realm', 'character_region'],
                'uq_dungeon_run_member'
            );
            $table->index('dungeon_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dungeon_run_members');
    }
};
