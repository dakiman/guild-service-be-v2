<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-run affixes were identical for every run in a period+region and read by
// nothing; the set now lives once on game_data_periods (pre-S2 ladder data is
// throwaway, so neither direction backfills).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_data_periods', function (Blueprint $table) {
            $table->jsonb('affix_ids')->nullable();
        });

        Schema::table('ladder_runs', function (Blueprint $table) {
            $table->dropColumn('affixes');
        });
    }

    public function down(): void
    {
        Schema::table('game_data_periods', function (Blueprint $table) {
            $table->dropColumn('affix_ids');
        });

        Schema::table('ladder_runs', function (Blueprint $table) {
            $table->jsonb('affixes')->default('[]');
        });
    }
};
