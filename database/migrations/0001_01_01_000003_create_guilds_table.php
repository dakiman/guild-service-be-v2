<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guilds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('realm');
            $table->string('region', 2);
            $table->string('faction', 20);
            $table->integer('achievement_points')->default(0);
            $table->integer('member_count')->default(0);
            $table->bigInteger('created_timestamp')->default(0);
            $table->integer('num_of_searches')->default(0);
            $table->timestamp('last_searched_at')->nullable();
            $table->timestamp('roster_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'realm', 'region']);
            $table->index('num_of_searches');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guilds');
    }
};
