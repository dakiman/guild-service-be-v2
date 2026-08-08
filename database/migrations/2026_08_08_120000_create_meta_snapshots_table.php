<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('period_id');
            $table->string('region', 4)->default('all'); // 'all' = cross-region rollup
            $table->string('section', 20);
            $table->jsonb('payload');
            $table->timestampTz('computed_at');
            $table->timestamps();

            $table->unique(['period_id', 'region', 'section'], 'uq_meta_snapshot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_snapshots');
    }
};
