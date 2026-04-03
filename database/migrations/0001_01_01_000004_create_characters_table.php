<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guild_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('realm');
            $table->string('region', 2);

            // Basic stats (flattened from old CharacterBasic DTO)
            $table->string('gender', 20)->nullable();
            $table->string('faction', 20)->nullable();
            $table->smallInteger('race_id')->nullable();
            $table->smallInteger('class_id')->nullable();
            $table->smallInteger('level')->nullable();
            $table->integer('achievement_points')->default(0);
            $table->smallInteger('average_item_level')->default(0);
            $table->smallInteger('equipped_item_level')->default(0);
            $table->string('active_specialization', 100)->nullable();

            // JSONB columns for atomic read/write data
            $table->jsonb('media')->nullable();
            $table->jsonb('talents')->nullable();
            $table->jsonb('equipment')->nullable();

            $table->boolean('recruitment')->default(false);
            $table->integer('num_of_searches')->default(0);
            $table->timestamp('last_searched_at')->nullable();
            $table->timestamp('mythics_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'realm', 'region']);
            $table->index('num_of_searches');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
