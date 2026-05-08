<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE raid_encounter_kills ALTER COLUMN difficulty TYPE varchar(32)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE raid_encounter_kills ALTER COLUMN difficulty TYPE varchar(16)');
    }
};
