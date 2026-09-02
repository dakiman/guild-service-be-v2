<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The season current_mythic_rating belongs to (max id of the base M+
     * profile's seasons[] list). Blizzard does not reset the rating at a
     * season boundary, so rating_synced_at alone cannot tell a stale MN-1
     * number from a live MN-2 one. Null = unknown (backfilled by
     * ratings:backfill-season where provable, otherwise set on next sync).
     * No index: the nightly full scan and the unselective IS NULL backlog
     * predicate don't benefit from one.
     */
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedSmallInteger('rating_season_id')->nullable()->after('rating_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('rating_season_id');
        });
    }
};
