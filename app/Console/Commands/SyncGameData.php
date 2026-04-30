<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Mappers\GameDataFactionMapper;
use App\Blizzard\Mappers\GameDataMountMapper;
use App\Blizzard\Mappers\GameDataTitleMapper;
use App\Models\GameDataFaction;
use App\Models\GameDataMount;
use App\Models\GameDataTitle;
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
        GameDataTitleMapper $titleMapper,
        GameDataMountMapper $mountMapper,
    ): int {
        $resource = $this->argument('resource');

        $resources = $resource === null
            ? ['factions', 'titles', 'mounts']
            : [$resource];

        foreach ($resources as $r) {
            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                'titles' => $this->syncTitles($client, $titleMapper),
                'mounts' => $this->syncMounts($client, $mountMapper),
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

    private function syncTitles(
        BlizzardGameDataClient $client,
        GameDataTitleMapper $mapper,
    ): void {
        $this->info('Syncing titles...');

        $index = $client->getTitleIndex();
        if ($index === null) {
            $this->warn('Title index returned null (404). Skipping.');

            return;
        }

        $ids = $mapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' title IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $ids, &$upserted, &$skipped, $bar) {
            foreach ($ids as $id) {
                try {
                    $detail = $client->getTitle($id);
                } catch (Throwable $e) {
                    Log::warning("Title sync skipped id={$id}: ".$e->getMessage());
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

                GameDataTitle::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name_male' => $dto->nameMale,
                        'name_female' => $dto->nameFemale,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Titles synced: {$upserted} upserted, {$skipped} skipped.");
    }

    private function syncMounts(
        BlizzardGameDataClient $client,
        GameDataMountMapper $mapper,
    ): void {
        $this->info('Syncing mounts...');

        $index = $client->getMountIndex();
        if ($index === null) {
            $this->warn('Mount index returned null (404). Skipping.');

            return;
        }

        $ids = $mapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' mount IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $ids, &$upserted, &$skipped, $bar) {
            foreach ($ids as $id) {
                try {
                    $detail = $client->getMount($id);
                } catch (Throwable $e) {
                    Log::warning("Mount sync skipped id={$id}: ".$e->getMessage());
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

                GameDataMount::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'description' => $dto->description,
                        'source_text' => $dto->sourceText,
                        'summon_spell_id' => $dto->summonSpellId,
                        'item_id' => $dto->itemId,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Mounts synced: {$upserted} upserted, {$skipped} skipped.");
    }
}
