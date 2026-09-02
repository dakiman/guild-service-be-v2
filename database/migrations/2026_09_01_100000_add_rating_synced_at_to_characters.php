<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->timestampTz('rating_synced_at')->nullable()->after('mythic_plus_rating_color');
        });

        // One-time backfill: any row touched since the current season started
        // was synced since then (a lookup always dispatches a sync), so
        // updated_at is a safe upper bound for the very first population.
        $seasonStart = DB::table('game_data_seasons')->where('is_current', true)->value('started_at');
        if ($seasonStart !== null) {
            DB::table('characters')
                ->whereNotNull('mythic_plus_rating')
                ->where('updated_at', '>=', $seasonStart)
                ->update(['rating_synced_at' => DB::raw('updated_at')]);
        }
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('rating_synced_at');
        });
    }
};
