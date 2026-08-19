<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Postgres doesn't auto-index FK columns: the warm's members⋈runs join and the
// cascadeOnDelete both seq-scanned the whole table. spec_id makes the index
// cover the specs aggregation. ~859k rows today — builds in seconds.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ladder_run_members', function (Blueprint $table) {
            $table->index(['ladder_run_id', 'spec_id'], 'ladder_run_members_run_spec_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ladder_run_members', function (Blueprint $table) {
            $table->dropIndex('ladder_run_members_run_spec_idx');
        });
    }
};
