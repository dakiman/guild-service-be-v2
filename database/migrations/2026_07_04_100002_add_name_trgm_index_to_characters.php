<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction. Disabling the
     * migration transaction lets the Postgres path build the trigram GIN index
     * without locking characters against writes on the live table. (B8)
     */
    public $withinTransaction = false;

    private const NAME = 'characters_name_trgm_idx';

    public function up(): void
    {
        // Speeds up Character::scopeNameSearch's `name LIKE '%x%'`, which a
        // b-tree cannot serve. pg_trgm only; SQLite has no trigram support and
        // LIKE is fine there, so this is a no-op off Postgres.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $e) {
            // A privilege failure (e.g. managed Postgres without superuser) must
            // not break deploys — log and skip the index rather than aborting.
            Log::warning('Skipping characters_name_trgm_idx: could not enable pg_trgm: '.$e->getMessage());

            return;
        }

        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.
            ' ON characters USING gin (name gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        // Drop only the index — never the extension (other objects may use it).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
    }
};
