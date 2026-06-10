<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raid_encounter_kills', function (Blueprint $table) {
            $table->string('difficulty', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('raid_encounter_kills', function (Blueprint $table) {
            $table->string('difficulty', 16)->change();
        });
    }
};
