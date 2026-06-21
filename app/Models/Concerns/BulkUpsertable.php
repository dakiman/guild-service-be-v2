<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Bulk INSERT ... ON CONFLICT DO UPDATE keyed on the model's UNIQUE_KEY.
 *
 * Replaces per-row updateOrCreate loops (one round-trip per DTO) with a single
 * chunked upsert. ON CONFLICT is atomic so concurrent syncs can't race into a
 * 23505 the way check-then-insert can. Eloquent's upsert() manages timestamps
 * and applies the update set itself. (P2.1)
 *
 * Implementing models must declare `public const UNIQUE_KEY = [...]` matching a
 * real unique index — Postgres requires a matching constraint as the conflict
 * target.
 */
trait BulkUpsertable
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  full rows incl. the key columns
     */
    public static function upsertMany(array $rows, int $chunkSize = 1000): void
    {
        if ($rows === []) {
            return;
        }

        // Update every column except the conflict-target key columns.
        $update = array_values(array_diff(array_keys($rows[0]), static::UNIQUE_KEY));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            static::upsert($chunk, static::UNIQUE_KEY, $update);
        }
    }
}
