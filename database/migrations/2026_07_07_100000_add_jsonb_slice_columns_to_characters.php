<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Whole-set-replace display data; resolved to names at read time
            // via game_data_titles / game_data_factions.
            $table->jsonb('title_ids')->nullable()->after('active_title_id');
            $table->jsonb('reputations')->nullable()->after('reputations_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['title_ids', 'reputations']);
        });
    }
};
