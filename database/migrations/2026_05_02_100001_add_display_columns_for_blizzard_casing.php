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
            $table->string('display_name')->nullable()->after('name');
            $table->string('display_realm')->nullable()->after('realm');
        });

        Schema::table('guild_members', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('name');
            $table->string('display_realm')->nullable()->after('realm');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('name');
            $table->string('display_realm')->nullable()->after('realm');
        });

        Schema::table('dungeon_run_members', function (Blueprint $table) {
            $table->string('display_realm')->nullable()->after('character_realm');
        });
    }

    public function down(): void
    {
        Schema::table('guilds', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'display_realm']);
        });

        Schema::table('guild_members', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'display_realm']);
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'display_realm']);
        });

        Schema::table('dungeon_run_members', function (Blueprint $table) {
            $table->dropColumn('display_realm');
        });
    }
};
