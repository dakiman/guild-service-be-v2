<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('active_specialization_id')->nullable()->after('active_specialization');
            $table->unsignedInteger('talent_tree_id')->nullable()->after('active_specialization_id');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['active_specialization_id', 'talent_tree_id']);
        });
    }
};
