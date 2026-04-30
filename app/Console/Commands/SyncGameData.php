<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Mappers\GameDataFactionMapper;
use App\Models\GameDataFaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGameData extends Command
{
    protected $signature = 'blizzard:sync-game-data {resource? : factions|titles|mounts|achievements; omit for all}';

    protected $description = 'Sync static reference data (factions/titles/mounts/achievements) from Blizzard Game Data API into game_data_* tables';

    public function handle(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $factionMapper,
    ): int {
        $resource = $this->argument('resource');

        $resources = $resource === null
            ? ['factions']
            : [$resource];

        // Plan-5-titles, mounts, achievements add their cases below as they ship.

        foreach ($resources as $r) {
            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                default => $this->error("Unknown resource: {$r}") || self::FAILURE,
            };
        }

        return self::SUCCESS;
    }

    private function syncFactions(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $mapper,
    ): void {
        $this->info('Syncing factions...');

        // The container resolves a region-bound instance — see
        // BlizzardServiceProvider::register() — so we don't set it here.
        // For multi-region sync, pass a per-region instance: see
        // SyncCharacterData::handle() (line ~178) for the per-region
        // construction pattern.

        $index = $client->getFactionIndex();
        if ($index === null) {
            $this->warn('Faction index returned null (404). Skipping.');

            return;
        }

        $ids = $mapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' faction IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $ids, &$upserted, &$skipped, $bar) {
            foreach ($ids as $id) {
                try {
                    $detail = $client->getFaction($id);
                } catch (Throwable $e) {
                    Log::warning("Faction sync skipped id={$id}: ".$e->getMessage());
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $dto = $mapper->mapDetail($detail);
                if ($dto === null) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                GameDataFaction::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'parent_faction_id' => $dto->parentFactionId,
                        'expansion_id' => $dto->expansionId,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Factions synced: {$upserted} upserted, {$skipped} skipped.");
    }
}
