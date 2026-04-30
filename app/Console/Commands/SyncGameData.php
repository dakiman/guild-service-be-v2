<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Mappers\GameDataAchievementCategoryMapper;
use App\Blizzard\Mappers\GameDataAchievementMapper;
use App\Blizzard\Mappers\GameDataFactionMapper;
use App\Blizzard\Mappers\GameDataMountMapper;
use App\Blizzard\Mappers\GameDataTitleMapper;
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
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

    private const ACHIEVEMENT_CHUNK_SIZE = 500;

    public function handle(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $factionMapper,
        GameDataTitleMapper $titleMapper,
        GameDataMountMapper $mountMapper,
        GameDataAchievementCategoryMapper $achievementCategoryMapper,
        GameDataAchievementMapper $achievementMapper,
    ): int {
        $resource = $this->argument('resource');

        $resources = $resource === null
            ? ['factions', 'titles', 'mounts', 'achievements']
            : [$resource];

        foreach ($resources as $r) {
            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                'titles' => $this->syncTitles($client, $titleMapper),
                'mounts' => $this->syncMounts($client, $mountMapper),
                'achievements' => $this->syncAchievements($client, $achievementCategoryMapper, $achievementMapper),
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

    /**
     * Two-phase sync: categories first (FK target for achievements), then
     * achievements in chunks (~40k rows; one DB::transaction over the whole
     * thing holds locks too long, so we wrap each chunk in its own transaction).
     */
    private function syncAchievements(
        BlizzardGameDataClient $client,
        GameDataAchievementCategoryMapper $categoryMapper,
        GameDataAchievementMapper $achievementMapper,
    ): void {
        $this->info('Syncing achievement categories...');

        $catIndex = $client->getAchievementCategoryIndex();
        if ($catIndex === null) {
            $this->warn('Achievement-category index returned null (404). Skipping.');

            return;
        }
        $catIds = $categoryMapper->extractIndexIds($catIndex);
        $this->info('Index returned '.count($catIds).' category IDs.');

        $bar = $this->output->createProgressBar(count($catIds));
        $bar->start();
        $catUpserted = 0;
        $catSkipped = 0;

        DB::transaction(function () use ($client, $categoryMapper, $catIds, &$catUpserted, &$catSkipped, $bar) {
            foreach ($catIds as $id) {
                try {
                    $detail = $client->getAchievementCategory($id);
                } catch (Throwable $e) {
                    Log::warning("Achievement-category sync skipped id={$id}: ".$e->getMessage());
                    $catSkipped++;
                    $bar->advance();

                    continue;
                }

                $dto = $categoryMapper->mapDetail($detail);
                if ($dto === null) {
                    $catSkipped++;
                    $bar->advance();

                    continue;
                }

                GameDataAchievementCategory::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'parent_id' => $dto->parentId,
                        'display_order' => $dto->displayOrder,
                    ],
                );
                $catUpserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Categories synced: {$catUpserted} upserted, {$catSkipped} skipped.");

        // ---- Phase 2: achievements ----
        $this->info('Syncing achievements...');

        $achIndex = $client->getAchievementIndex();
        if ($achIndex === null) {
            $this->warn('Achievement index returned null (404). Skipping.');

            return;
        }
        $achIds = $achievementMapper->extractIndexIds($achIndex);
        $this->info('Index returned '.count($achIds).' achievement IDs.');

        $bar = $this->output->createProgressBar(count($achIds));
        $bar->start();
        $achUpserted = 0;
        $achSkipped = 0;

        // Process in chunks. Each chunk: fetch all detail rows, then one
        // DB::transaction wrapping the chunk's upserts.
        foreach (array_chunk($achIds, self::ACHIEVEMENT_CHUNK_SIZE) as $chunk) {
            $rows = [];

            foreach ($chunk as $id) {
                try {
                    $detail = $client->getAchievement($id);
                } catch (Throwable $e) {
                    Log::warning("Achievement sync skipped id={$id}: ".$e->getMessage());
                    $achSkipped++;
                    $bar->advance();

                    continue;
                }

                $dto = $achievementMapper->mapDetail($detail);
                if ($dto === null) {
                    $achSkipped++;
                    $bar->advance();

                    continue;
                }

                $rows[] = $dto;
            }

            DB::transaction(function () use ($rows, &$achUpserted, $bar) {
                foreach ($rows as $dto) {
                    GameDataAchievement::updateOrCreate(
                        ['id' => $dto->id],
                        [
                            'name' => $dto->name,
                            'description' => $dto->description,
                            'category_id' => $dto->categoryId,
                            'points' => $dto->points,
                            'is_account_wide' => $dto->isAccountWide,
                        ],
                    );
                    $achUpserted++;
                    $bar->advance();
                }
            });
        }

        $bar->finish();
        $this->newLine();
        $this->info("Achievements synced: {$achUpserted} upserted, {$achSkipped} skipped.");
    }
}
