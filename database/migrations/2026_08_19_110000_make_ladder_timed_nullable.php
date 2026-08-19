<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// null = "timer unknown at ingest" (dungeon row or keystone_upgrades missing —
// rollover window, mid-season pool change). run_hash dedupe makes ingest-time
// verdicts permanent, so unknown must be representable and repairable
// (ladder:recompute-timed) instead of frozen false.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ladder_runs', function (Blueprint $table) {
            $table->boolean('is_completed_on_time')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ladder_runs', function (Blueprint $table) {
            $table->boolean('is_completed_on_time')->default(false)->change();
        });
    }
};
