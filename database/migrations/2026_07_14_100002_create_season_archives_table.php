<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_archives', function (Blueprint $table) {
            $table->unsignedSmallInteger('season_id')->primary();
            // One immutable blob per season: everything the /mythic-plus page
            // rendered on the season's last day. Written once by
            // season:rollover; never recomputed from live tables.
            $table->jsonb('payload');
            $table->timestampTz('snapshotted_at');
            $table->timestamps();

            $table->foreign('season_id')
                ->references('id')->on('game_data_seasons')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_archives');
    }
};
