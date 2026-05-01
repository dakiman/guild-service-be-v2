<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_professions', function (Blueprint $table) {
            $table->unsignedSmallInteger('expansion_id')->nullable()->after('tier_name');

            $table->foreign('expansion_id')
                ->references('id')
                ->on('game_data_expansions')
                ->nullOnDelete();

            $table->index('expansion_id');
        });
    }

    public function down(): void
    {
        Schema::table('character_professions', function (Blueprint $table) {
            $table->dropForeign(['expansion_id']);
            $table->dropIndex(['expansion_id']);
            $table->dropColumn('expansion_id');
        });
    }
};
