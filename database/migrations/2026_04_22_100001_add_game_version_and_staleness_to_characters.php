<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('game_version', 20)->default('retail')->after('region');
            $table->smallInteger('mythic_plus_rating')->nullable()->after('equipped_item_level');
            $table->jsonb('mythic_plus_rating_by_spec')->nullable()->after('mythic_plus_rating');
            $table->string('talent_loadout_code', 255)->nullable()->after('active_specialization');
            $table->timestamp('pvp_synced_at')->nullable()->after('mythics_synced_at');
            $table->timestamp('professions_synced_at')->nullable()->after('pvp_synced_at');
            $table->timestamp('raids_synced_at')->nullable()->after('professions_synced_at');
        });

        // Swap the unique index to include game_version.
        // Safe: no game_version='classic' rows can exist at this point, so existing
        // (name, realm, region) uniqueness still holds for (name, realm, region, 'retail').
        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique('characters_name_realm_region_unique');
            $table->unique(['name', 'realm', 'region', 'game_version'], 'characters_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique('characters_identity_unique');
            $table->unique(['name', 'realm', 'region'], 'characters_name_realm_region_unique');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn([
                'game_version',
                'mythic_plus_rating',
                'mythic_plus_rating_by_spec',
                'talent_loadout_code',
                'pvp_synced_at',
                'professions_synced_at',
                'raids_synced_at',
            ]);
        });
    }
};
