<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seeded_runs', function (Blueprint $table) {
            $table->bigInteger('keystone_run_id')->primary();
            $table->string('region', 8);
            $table->timestamp('seeded_at')->useCurrent();

            $table->index('seeded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seeded_runs');
    }
};
