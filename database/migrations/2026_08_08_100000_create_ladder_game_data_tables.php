<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_periods', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('period_id');
            $table->string('region', 4);
            $table->timestampTz('start_at')->nullable();
            $table->timestampTz('end_at')->nullable();
            $table->timestamps();

            $table->unique(['region', 'period_id']);
        });

        Schema::create('game_data_connected_realms', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('connected_realm_id');
            $table->string('region', 4);
            $table->jsonb('realm_slugs')->default('[]');
            $table->timestamps();

            $table->unique(['region', 'connected_realm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_connected_realms');
        Schema::dropIfExists('game_data_periods');
    }
};
