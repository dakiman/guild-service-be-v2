<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction. Disabling the
     * migration transaction lets the Postgres path build the indexes without
     * locking characters against writes on the live table.
     */
    public $withinTransaction = false;

    /**
     * Supports CharacterStatsService::getTopBy():
     *   WHERE level = ? AND <col> > ? ORDER BY <col> DESC LIMIT 5
     * Composite (level, <col> DESC) instead of a partial index because both
     * the level and the > 0 bound are query parameters, so a partial
     * predicate can't be proven under a generic prepared-statement plan.
     */
    private const INDEXES = [
        'characters_level_mythic_plus_rating_idx' => 'mythic_plus_rating',
        'characters_level_average_item_level_idx' => 'average_item_level',
        'characters_level_achievement_points_idx' => 'achievement_points',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $column) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.
                    ' ON characters (level, '.$column.' DESC)'
                );

                continue;
            }

            // SQLite / others: plain index (no CONCURRENTLY support).
            if (! $this->indexExists($name)) {
                DB::statement(
                    'CREATE INDEX '.$name.
                    ' ON characters (level, '.$column.' DESC)'
                );
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);

                continue;
            }

            DB::statement('DROP INDEX IF EXISTS '.$name);
        }
    }

    private function indexExists(string $name): bool
    {
        return collect(Schema::getIndexes('characters'))
            ->contains(fn ($index) => $index['name'] === $name);
    }
};
