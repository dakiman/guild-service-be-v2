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
     * locking characters against writes on the live table. (P2.5)
     */
    public $withinTransaction = false;

    private const NAME = 'characters_mplus_crawl_idx';

    public function up(): void
    {
        // Supports CrawlMythicPlusRuns: WHERE game_version ORDER BY mythic_plus_rating DESC.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.
                ' ON characters (game_version, mythic_plus_rating DESC)'
            );

            return;
        }

        // SQLite / others: plain index (no CONCURRENTLY support).
        if (! $this->indexExists()) {
            DB::statement(
                'CREATE INDEX '.self::NAME.
                ' ON characters (game_version, mythic_plus_rating DESC)'
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
        return collect(Schema::getIndexes('characters'))
            ->contains(fn ($index) => $index['name'] === self::NAME);
    }
};
