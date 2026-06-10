<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->index('achievement_points');
            $table->index('member_count');
            $table->index('created_timestamp');
            $table->index('last_searched_at');
        });
    }

    public function down(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->dropIndex(['achievement_points']);
            $table->dropIndex(['member_count']);
            $table->dropIndex(['created_timestamp']);
            $table->dropIndex(['last_searched_at']);
        });
    }
};
