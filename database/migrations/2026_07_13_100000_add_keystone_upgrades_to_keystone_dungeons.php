<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_data_mythic_keystone_dungeons', function (Blueprint $table) {
            // Blizzard par times: [{upgrade_level, qualifying_duration(ms)}, ...]
            // Read-side chest count = thresholds beaten by a run's duration.
            $table->jsonb('keystone_upgrades')->nullable()->after('media_url');
        });
    }

    public function down(): void
    {
        Schema::table('game_data_mythic_keystone_dungeons', function (Blueprint $table) {
            $table->dropColumn('keystone_upgrades');
        });
    }
};
