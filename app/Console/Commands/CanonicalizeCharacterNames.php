<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Character;
use App\Support\BlizzardIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off data repair for the 2026-07 ASCII-only `strtolower()` regression.
 *
 * Three passes:
 *  1. characters      — mb-lowercase `name`, merging case-duplicate rows into the canonical keeper.
 *  2. guild_members   — mb-lowercase `name`, deleting rows whose canonical sibling already exists.
 *  3. relink          — backfill `guild_members.character_id` for rows that never linked.
 *
 * Idempotent: a second run finds nothing to do.
 *
 * NOTE on `--dry-run` counts: the dry run performs no writes, so the keeper lookup
 * cannot see renames it *would* have made. A group with two non-canonical casings and
 * no canonical row is therefore reported as 2 renames, whereas the real run performs
 * 1 rename + 1 merge. Small divergences between the dry-run and real-run summaries are
 * expected and not a sign of trouble.
 */
class CanonicalizeCharacterNames extends Command
{
    protected $signature = 'characters:canonicalize-names {--dry-run : Report what would change without writing}';

    protected $description = 'One-off repair: mb-lowercase character/guild-member names, merge case-duplicate character rows, relink guild members (2026-07 strtolower regression)';

    /** unique key columns per slice table (besides character_id) */
    private const SLICE_TABLES = [
        'character_achievements' => ['achievement_id'],
        'character_mounts' => ['mount_id'],
        'character_pets' => ['pet_id'],
        'character_toys' => ['toy_id'],
        'character_professions' => ['profession_id', 'tier_name'],
        'character_pvp_brackets' => ['bracket'],
        'raid_encounter_kills' => ['encounter_id', 'difficulty'],
    ];

    /** Correlated EXISTS used by the relink pass; `guild_members` is referenced unaliased (SQLite + PG). */
    private const RELINK_MATCH = <<<'SQL'
        SELECT c.id FROM characters c
        WHERE c.name = guild_members.name
          AND c.realm = guild_members.realm
          AND c.game_version = 'retail'
          AND c.region = (SELECT g.region FROM guilds g WHERE g.id = guild_members.guild_id)
        SQL;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('Dry run — no writes will be performed.');
        }

        [$renamed, $merged] = $this->canonicalizeCharacters($dry);
        [$gmRenamed, $gmDeleted] = $this->canonicalizeGuildMembers($dry);
        $relinked = $this->relinkGuildMembers($dry);

        $this->info("characters: {$renamed} renamed, {$merged} merged; guild_members: {$gmRenamed} renamed, {$gmDeleted} deleted, {$relinked} relinked");

        return self::SUCCESS;
    }

    /**
     * Pass 1 — characters.
     *
     * @return array{0: int, 1: int} [renamed, merged]
     */
    private function canonicalizeCharacters(bool $dry): array
    {
        $renamed = 0;
        $merged = 0;

        Character::query()
            ->select([
                'id', 'user_id', 'guild_id', 'name', 'display_name', 'realm', 'region',
                'game_version', 'num_of_searches', 'last_searched_at', 'mythic_plus_rating_by_spec',
            ])
            ->chunkById(1000, function ($chunk) use (&$renamed, &$merged, $dry): void {
                foreach ($chunk as $character) {
                    $canonical = BlizzardIdentity::name((string) $character->name);

                    if ($canonical === $character->name) {
                        continue;
                    }

                    $keeper = Character::query()
                        ->where('name', $canonical)
                        ->where('realm', $character->realm)
                        ->where('region', $character->region)
                        ->where('game_version', $character->game_version)
                        ->first();

                    if ($keeper !== null) {
                        $merged++;

                        if (! $dry) {
                            DB::transaction(fn () => $this->mergeInto($keeper, $character));
                        }

                        continue;
                    }

                    $renamed++;

                    if (! $dry) {
                        DB::transaction(fn () => $this->renameCharacter($character, $canonical));
                    }
                }
            });

        return [$renamed, $merged];
    }

    /**
     * Fold the non-canonical $loser row into the canonical $keeper row and drop the loser.
     */
    private function mergeInto(Character $keeper, Character $loser): void
    {
        // No unique key involves character_id on these two — a plain repoint cannot conflict.
        DB::table('dungeon_run_members')
            ->where('character_id', $loser->id)
            ->update(['character_id' => $keeper->id]);

        DB::table('guild_members')
            ->where('character_id', $loser->id)
            ->update(['character_id' => $keeper->id]);

        foreach (self::SLICE_TABLES as $table => $keyColumns) {
            $match = implode(' AND ', array_map(
                static fn (string $column): string => "k.{$column} = {$table}.{$column}",
                $keyColumns,
            ));

            // Inner table aliased `k`, outer table referenced unaliased — required by SQLite, valid on PG.
            DB::update(
                <<<SQL
                UPDATE {$table} SET character_id = ?
                WHERE character_id = ?
                  AND NOT EXISTS (
                    SELECT 1 FROM {$table} k
                    WHERE k.character_id = ? AND {$match}
                  )
                SQL,
                [$keeper->id, $loser->id, $keeper->id],
            );

            // Only sanctioned deletion: leftovers are rows the keeper already has.
            // Slice data is fully re-syncable (the keeper's next Full/StaleOnly sync rebuilds it).
            DB::table($table)->where('character_id', $loser->id)->delete();
        }

        $keeper->num_of_searches = (int) $keeper->num_of_searches + (int) $loser->num_of_searches;
        $keeper->last_searched_at = collect([$keeper->last_searched_at, $loser->last_searched_at])->filter()->max();
        // The loser's capitalized name IS the display casing.
        $keeper->display_name ??= $loser->display_name ?? $loser->name;
        $keeper->mythic_plus_rating_by_spec ??= $loser->mythic_plus_rating_by_spec;
        $keeper->user_id ??= $loser->user_id;
        $keeper->guild_id ??= $loser->guild_id;
        $keeper->save();

        $loser->delete();
    }

    private function renameCharacter(Character $character, string $canonical): void
    {
        $character->display_name ??= $character->name;
        $character->name = $canonical;
        $character->save();
    }

    /**
     * Pass 2 — guild_members.
     *
     * @return array{0: int, 1: int} [renamed, deleted]
     */
    private function canonicalizeGuildMembers(bool $dry): array
    {
        $renamed = 0;
        $deleted = 0;

        DB::table('guild_members')
            ->select(['id', 'guild_id', 'name', 'display_name', 'realm'])
            ->chunkById(1000, function ($chunk) use (&$renamed, &$deleted, $dry): void {
                foreach ($chunk as $member) {
                    $canonical = BlizzardIdentity::name((string) $member->name);

                    if ($canonical === $member->name) {
                        continue;
                    }

                    $siblingExists = DB::table('guild_members')
                        ->where('guild_id', $member->guild_id)
                        ->where('name', $canonical)
                        ->where('realm', $member->realm)
                        ->exists();

                    if ($siblingExists) {
                        // Stale case-duplicate: the fixed roster sync's delete-missing would prune it anyway.
                        $deleted++;

                        if (! $dry) {
                            DB::transaction(fn () => DB::table('guild_members')->where('id', $member->id)->delete());
                        }

                        continue;
                    }

                    $renamed++;

                    if (! $dry) {
                        DB::transaction(fn () => DB::table('guild_members')
                            ->where('id', $member->id)
                            ->update([
                                'name' => $canonical,
                                'display_name' => $member->display_name ?? $member->name,
                                'updated_at' => now(),
                            ]));
                    }
                }
            });

        return [$renamed, $deleted];
    }

    /**
     * Pass 3 — backfill guild_members.character_id for rows that never linked.
     *
     * The scalar subquery is safe: the `characters` identity unique index guarantees ≤1 match.
     */
    private function relinkGuildMembers(bool $dry): int
    {
        $match = self::RELINK_MATCH;

        if ($dry) {
            return DB::table('guild_members')
                ->whereNull('character_id')
                ->whereRaw("EXISTS ({$match})")
                ->count();
        }

        return DB::update(
            <<<SQL
            UPDATE guild_members SET character_id = ({$match})
            WHERE character_id IS NULL
              AND EXISTS ({$match})
            SQL
        );
    }
}
