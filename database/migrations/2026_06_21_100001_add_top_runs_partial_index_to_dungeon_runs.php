<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction. Disabling the
     * migration transaction lets the Postgres path build the index without
     * locking dungeon_runs against writes on the live table. (P2.2)
     */
    public $withinTransaction = false;

    private const NAME = 'dungeon_runs_top_runs_idx';

    public function up(): void
    {
        // Supports TopRuns/TopKeys: WHERE is_completed_on_time ORDER BY keystone_level DESC, duration.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.
                ' ON dungeon_runs (keystone_level DESC, duration) WHERE is_completed_on_time'
            );

            return;
        }

        // SQLite / others: plain partial index (no CONCURRENTLY support).
        if (! $this->indexExists()) {
            DB::statement(
                'CREATE INDEX '.self::NAME.
                ' ON dungeon_runs (keystone_level DESC, duration) WHERE is_completed_on_time = 1'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::NAME);
    }

    private function indexExists(): bool
    {
        return collect(Schema::getIndexes('dungeon_runs'))
            ->contains(fn ($index) => $index['name'] === self::NAME);
    }
};
