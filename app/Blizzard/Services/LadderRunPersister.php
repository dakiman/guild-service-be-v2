<?php

declare(strict_types=1);

namespace App\Blizzard\Services;

use App\Models\LadderRun;
use App\Models\LadderRunMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class LadderRunPersister
{
    /**
     * @param  list<array{run: array<string,mixed>, members: list<array<string,mixed>>}>  $mapped
     * @return array{inserted: int, skipped: int}
     */
    public function persist(array $mapped): array
    {
        if ($mapped === []) {
            return ['inserted' => 0, 'skipped' => 0];
        }

        $hashes = array_map(fn (array $entry): string => $entry['run']['run_hash'], $mapped);
        $existing = array_flip(
            LadderRun::query()->whereIn('run_hash', $hashes)->pluck('run_hash')->all(),
        );
        $fresh = array_values(array_filter($mapped, fn (array $e): bool => ! isset($existing[$e['run']['run_hash']])));

        $inserted = 0;
        foreach (array_chunk($fresh, 100) as $chunk) {
            DB::transaction(function () use ($chunk, &$inserted): void {
                foreach ($chunk as $entry) {
                    try {
                        // Nested transaction = SAVEPOINT on Postgres: a unique
                        // violation aborts only the inner transaction, so a
                        // racing duplicate skips this entry without poisoning
                        // the rest of the chunk (25P02).
                        DB::transaction(function () use ($entry, &$inserted): void {
                            $run = LadderRun::query()->create($entry['run']);

                            $now = now();
                            LadderRunMember::query()->insert(array_map(
                                fn (array $m): array => $m + ['ladder_run_id' => $run->id, 'created_at' => $now, 'updated_at' => $now],
                                $entry['members'],
                            ));
                            $inserted++;
                        });
                    } catch (UniqueConstraintViolationException) {
                        // Lost a race against a concurrent shard job holding the same run.
                        continue;
                    }
                }
            });
        }

        return ['inserted' => $inserted, 'skipped' => count($mapped) - $inserted];
    }
}
