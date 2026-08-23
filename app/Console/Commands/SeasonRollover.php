<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Jobs\WarmCharacterStats;
use App\Models\DungeonRun;
use App\Models\GameDataExpansion;
use App\Models\GameDataSeason;
use App\Services\SeasonArchiveService;
use App\Support\Seasons;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SeasonRollover extends Command
{
    protected $signature = 'season:rollover
        {--blizzard-id= : Blizzard integer id of the NEW season (validated against the live index)}
        {--slug= : raider.io-style season slug, e.g. season-mn-2}
        {--name= : Display name, e.g. "Midnight Season 2"}
        {--tier-slug= : raider.io raid tier slug, e.g. tier-mn-2}
        {--raiderio-expansion= : raider.io expansion_id for icon backfill (default: outgoing season\'s)}
        {--expansion-id= : game_data_expansions id (default: outgoing season\'s)}
        {--force : Overwrite an existing archive for the outgoing season}
        {--skip-sync : Skip the pve game-data sync and icon backfill steps}';

    protected $description = 'Roll the M+ season: snapshot the outgoing season to the archive, flip the registry, clear caches, sync the new dungeon pool';

    public function handle(BlizzardGameDataClient $client, SeasonArchiveService $archiveService): int
    {
        $old = GameDataSeason::where('is_current', true)->first();
        if ($old === null) {
            $this->error('No current season in game_data_seasons — seed the registry first (GameDataSeasonSeeder).');

            return self::FAILURE;
        }

        // ── 1. Resolve + validate the new season against the live index ──
        $index = $client->getMythicPlusSeasonIndex();
        $ids = array_map(fn (array $s) => (int) $s['id'], $index['seasons'] ?? []);
        $liveCurrent = (int) ($index['current_season']['id'] ?? 0);
        $this->info('Blizzard season index: ['.implode(', ', $ids)."], current_season={$liveCurrent}.");

        $newId = (int) ($this->option('blizzard-id')
            ?? $this->ask("New season's Blizzard id", $liveCurrent !== 0 && $liveCurrent !== $old->id ? (string) $liveCurrent : null));

        if (! in_array($newId, $ids, true)) {
            $this->error("Season {$newId} is not in Blizzard's season index — refusing to flip to a season Blizzard doesn't serve.");

            return self::FAILURE;
        }
        if ($newId === (int) $old->id) {
            $this->error("Season {$newId} is already the current season.");

            return self::FAILURE;
        }
        if ($liveCurrent !== 0 && $newId !== $liveCurrent) {
            $this->warn("Blizzard reports current_season={$liveCurrent} but you chose {$newId} — double-check before confirming.");
        }

        $slug = (string) ($this->option('slug') ?? $this->ask('New season slug (raider.io style, e.g. season-mn-2)'));
        $name = (string) ($this->option('name') ?? $this->ask('New season display name (e.g. "Midnight Season 2")'));
        $tierSlug = (string) ($this->option('tier-slug') ?? $this->ask('New raider.io raid tier slug (e.g. tier-mn-2)'));
        $raiderioExpansion = (int) ($this->option('raiderio-expansion') ?? $old->raiderio_expansion_id);
        $expansionId = (int) ($this->option('expansion-id') ?? $old->expansion_id ?? 0);

        // An expansion id we don't have a row for means the operator is
        // rolling across an expansion boundary before seeding it. Don't trip
        // the FK — store null and flag it in the checklist instead.
        $expansionMissing = $expansionId !== 0 && ! GameDataExpansion::whereKey($expansionId)->exists();

        // ── 2. Preflight summary ──
        $oldRuns = DungeonRun::where('season', $old->id)->count();
        $newRuns = DungeonRun::where('season', $newId)->count();
        $this->table(['', 'id', 'slug', 'name', 'runs in DB'], [
            ['outgoing', $old->id, $old->slug, $old->name, number_format($oldRuns)],
            ['incoming', $newId, $slug, $name, number_format($newRuns)],
        ]);
        $this->line("Incoming tier slug: {$tierSlug} · raider.io expansion: {$raiderioExpansion} · expansion_id: ".($expansionMissing ? "{$expansionId} (MISSING — will store null)" : $expansionId));

        if (! $this->confirm('Proceed with the rollover?')) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        // ── 3. Snapshot the outgoing season (before anything mutates) ──
        try {
            $archive = $archiveService->snapshot($old, force: (bool) $this->option('force'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Archived {$old->slug}: ".count($archive->payload['top_runs']).' top runs, '
            .count($archive->payload['top_keys']['dungeons']).' top keys, '
            .number_format($archive->payload['meta']['total_runs']).' total runs.');

        // ── 4. Flip the registry ──
        $startedAt = null;
        $seasonPayload = $client->getMythicKeystoneSeason($newId);
        if (isset($seasonPayload['start_timestamp'])) {
            $startedAt = Carbon::createFromTimestampMs($seasonPayload['start_timestamp']);
        }

        DB::transaction(function () use ($old, $newId, $slug, $name, $tierSlug, $raiderioExpansion, $expansionId, $expansionMissing, $startedAt) {
            // Old row first — the partial unique index allows only one
            // is_current=true row at any instant.
            $old->update(['is_current' => false, 'ended_at' => now()]);

            GameDataSeason::updateOrCreate(['id' => $newId], [
                'slug' => $slug,
                'name' => $name,
                'raiderio_tier_slug' => $tierSlug,
                'raiderio_expansion_id' => $raiderioExpansion,
                'expansion_id' => ($expansionMissing || $expansionId === 0) ? null : $expansionId,
                'is_current' => true,
                'started_at' => $startedAt ?? now(),
            ]);
        });
        $this->info("Registry flipped: {$slug} (id {$newId}) is now current.");

        // ── 5. Clear caches ──
        Seasons::clearCache();
        foreach ((array) config('blizzard.regions') as $region) {
            Cache::forget("blizzard:mythic-plus:current-season:{$region}");
        }
        Cache::forget('game-data:mythic-keystone-dungeons');
        Cache::forget('game-data:seasons');
        WarmCharacterStats::dispatch();
        $this->info('Caches cleared; stats warm dispatched.');

        // ── 6. New season's dungeon pool + icons ──
        if ($this->option('skip-sync')) {
            $this->warn('--skip-sync: run these yourself: blizzard:sync-game-data pve && dungeons:backfill-icons-from-raiderio --expansion='.$raiderioExpansion);
        } else {
            $this->call('blizzard:sync-game-data', ['resource' => 'pve']);
            $iconsRc = $this->call('dungeons:backfill-icons-from-raiderio', ['--expansion' => $raiderioExpansion]);
            if ($iconsRc !== self::SUCCESS) {
                $this->newLine();
                $this->warn('Icon backfill did NOT complete — new-season dungeons will render without icons until you run:');
                $this->warn('  dungeons:backfill-icons-from-raiderio --expansion='.$raiderioExpansion.' --dest=/tmp/dungeons');
                $this->warn('  then docker cp the files into frontend/public/dungeons/ and frontend/dist/dungeons/ on the host.');
            }
        }

        // ── 7. Expansion-boundary checklist ──
        if ($expansionMissing || ($expansionId !== 0 && $expansionId !== (int) $old->expansion_id)) {
            $this->newLine();
            $this->warn('Expansion boundary detected — manual steps this command cannot do:');
            $this->warn('  1. GameDataExpansionSeeder: add the new expansion row (display_order=1, bump the rest), then db:seed --class=GameDataExpansionSeeder');
            $this->warn('  2. GameDataRaidInstanceMapper::BLIZZARD_JOURNAL_EXPANSION_TO_OUR_ID: map the new journal-expansion id');
            $this->warn('  3. GameDataFactionMapper::FACTION_TO_EXPANSION: add new factions');
            $this->warn('  4. frontend/src/utils/wowConstants.ts + BE race mapper: new races');
            $this->warn('  5. Root CLAUDE.md: update the cross-repo context block');
            if ($expansionMissing) {
                $this->warn("  6. Then set game_data_seasons.expansion_id={$expansionId} on season {$newId} (stored null for now).");
            }
        }

        // ── 8. Always-relevant checklist ──
        $this->newLine();
        $this->line('- M+ ladder crawl: no manual action — blizzard:seed-ladders derives the dungeon pool from raider.io static-data for the current registry season (matched by slug); just confirm `blizzard:sync-game-data periods` has run since the rollover.');
        $this->line('- M+ brackets: confirm BLIZZARD_LADDER_BRACKETS floors match the new season\'s affix breakpoints, and update BRACKET_LABELS in frontend/src/utils/wowConstants.ts to the new affix names.');

        $this->newLine();
        $this->info('Season rollover complete. Frontend picks the new season up automatically.');

        return self::SUCCESS;
    }
}
