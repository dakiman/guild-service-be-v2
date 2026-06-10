<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dungeon_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('keystone_run_id')->nullable()->unique()->after('id');
            $table->decimal('raiderio_score', 6, 1)->nullable()->after('affixes');
            $table->text('raiderio_url')->nullable()->after('raiderio_score');
        });
    }

    public function down(): void
    {
        Schema::table('dungeon_runs', function (Blueprint $table) {
            $table->dropUnique(['keystone_run_id']);
            $table->dropColumn(['keystone_run_id', 'raiderio_score', 'raiderio_url']);
        });
    }
};
