<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Mappers\GameDataAchievementCategoryMapper;
use App\Blizzard\Mappers\GameDataAchievementMapper;
use App\Blizzard\Mappers\GameDataFactionMapper;
use App\Blizzard\Mappers\GameDataKeystoneAffixMapper;
use App\Blizzard\Mappers\GameDataMountMapper;
use App\Blizzard\Mappers\GameDataMythicKeystoneDungeonMapper;
use App\Blizzard\Mappers\GameDataRaidEncounterMapper;
use App\Blizzard\Mappers\GameDataRaidInstanceMapper;
use App\Blizzard\Mappers\GameDataTalentTreeMapper;
use App\Blizzard\Mappers\GameDataTitleMapper;
use App\Models\GameDataAchievement;
use App\Models\GameDataAchievementCategory;
use App\Models\GameDataFaction;
use App\Models\GameDataKeystoneAffix;
use App\Models\GameDataMount;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataRaidEncounter;
use App\Models\GameDataRaidInstance;
use App\Models\GameDataTalentTree;
use App\Models\GameDataTitle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGameData extends Command
{
    protected $signature = 'blizzard:sync-game-data {resource? : factions|titles|mounts|achievements|pve|talent-trees; omit for all}';

    protected $description = 'Sync static reference data (factions/titles/mounts/achievements/pve/talent-trees) from Blizzard Game Data API into game_data_* tables';

    private const ACHIEVEMENT_CHUNK_SIZE = 500;

    public function handle(
        BlizzardGameDataClient $client,
        GameDataFactionMapper $factionMapper,
        GameDataTitleMapper $titleMapper,
        GameDataMountMapper $mountMapper,
        GameDataAchievementCategoryMapper $achievementCategoryMapper,
        GameDataAchievementMapper $achievementMapper,
        GameDataRaidInstanceMapper $raidInstanceMapper,
        GameDataRaidEncounterMapper $raidEncounterMapper,
        GameDataMythicKeystoneDungeonMapper $dungeonMapper,
        GameDataKeystoneAffixMapper $affixMapper,
        GameDataTalentTreeMapper $talentTreeMapper,
    ): int {
        $resource = $this->argument('resource');

        $resources = $resource === null
            ? ['factions', 'titles', 'mounts', 'achievements', 'pve', 'talent-trees']
            : [$resource];

        foreach ($resources as $r) {
            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                'titles' => $this->syncTitles($client, $titleMapper),
                'mounts' => $this->syncMounts($client, $mountMapper),
                'achievements' => $this->syncAchievements($client, $achievementCategoryMapper, $achievementMapper),
                'pve' => $this->syncPve($client, $raidInstanceMapper, $raidEncounterMapper, $dungeonMapper, $affixMapper),
                'talent-trees' => $this->syncTalentTrees($client, $talentTreeMapper),
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

    /**
     * Sync the four PvE game-data tables. Sequence:
     *  1. Raid instances + their encounter rosters from the journal-instance
     *     family of endpoints. Encounters are fanned out per-instance with
     *     the instance id passed as the encounter's parent (the encounter
     *     detail response sometimes omits `instance.id`).
     *  2. Mythic-keystone dungeons (current season scope) — uses the season's
     *     `dungeons` list to know which IDs to sync, plus the dungeon-index
     *     for fields the season payload doesn't carry.
     *  3. Keystone affixes (full universe; ~12-16 rows) with their icons.
     */
    private function syncPve(
        BlizzardGameDataClient $client,
        GameDataRaidInstanceMapper $raidInstanceMapper,
        GameDataRaidEncounterMapper $raidEncounterMapper,
        GameDataMythicKeystoneDungeonMapper $dungeonMapper,
        GameDataKeystoneAffixMapper $affixMapper,
    ): void {
        $this->syncRaids($client, $raidInstanceMapper, $raidEncounterMapper);
        $this->syncMythicKeystoneDungeons($client, $dungeonMapper);
        $this->syncKeystoneAffixes($client, $affixMapper);
    }

    private function syncRaids(
        BlizzardGameDataClient $client,
        GameDataRaidInstanceMapper $instanceMapper,
        GameDataRaidEncounterMapper $encounterMapper,
    ): void {
        $this->info('Syncing raid instances + encounters...');

        $index = $client->getJournalInstanceIndex();
        if ($index === null) {
            $this->warn('Journal-instance index returned null (404). Skipping raids.');

            return;
        }

        $ids = $instanceMapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' raid instance IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $instUpserted = 0;
        $instSkipped = 0;
        $encUpserted = 0;
        $encSkipped = 0;

        foreach ($ids as $instanceId) {
            try {
                $detail = $client->getJournalInstance($instanceId);
                $media = $client->getJournalInstanceMedia($instanceId);
            } catch (Throwable $e) {
                Log::warning("Raid instance sync skipped id={$instanceId}: ".$e->getMessage());
                $instSkipped++;
                $bar->advance();

                continue;
            }

            $mediaUrl = $instanceMapper->extractMediaUrl($media);
            $instanceDto = $instanceMapper->mapDetail($detail, $mediaUrl);
            if ($instanceDto === null) {
                $instSkipped++;
                $bar->advance();

                continue;
            }

            DB::transaction(function () use (
                $client,
                $encounterMapper,
                $instanceDto,
                &$instUpserted,
                &$encUpserted,
                &$encSkipped,
            ) {
                GameDataRaidInstance::updateOrCreate(
                    ['id' => $instanceDto->id],
                    [
                        'name' => $instanceDto->name,
                        'expansion_id' => $instanceDto->expansionId,
                        'display_order' => $instanceDto->displayOrder,
                        'media_url' => $instanceDto->mediaUrl,
                    ],
                );
                $instUpserted++;

                foreach ($instanceDto->encounterIds as $i => $encounterId) {
                    try {
                        $encDetail = $client->getJournalEncounter($encounterId);
                    } catch (Throwable $e) {
                        Log::warning("Encounter sync skipped id={$encounterId}: ".$e->getMessage());
                        $encSkipped++;

                        continue;
                    }

                    $creatureDisplayId = isset($encDetail['creature_display']['id'])
                        ? (int) $encDetail['creature_display']['id']
                        : (isset($encDetail['creature_displays'][0]['id'])
                            ? (int) $encDetail['creature_displays'][0]['id']
                            : null);

                    $portraitUrl = null;
                    if ($creatureDisplayId !== null) {
                        try {
                            $cdMedia = $client->getCreatureDisplayMedia($creatureDisplayId);
                            $portraitUrl = $encounterMapper->extractMediaUrl($cdMedia);
                        } catch (Throwable $e) {
                            Log::warning("Creature-display media skipped id={$creatureDisplayId}: ".$e->getMessage());
                        }
                    }

                    $encDto = $encounterMapper->mapDetail(
                        detail: $encDetail,
                        portraitUrl: $portraitUrl,
                        fallbackInstanceId: $instanceDto->id,
                        fallbackOrder: $i,
                    );
                    if ($encDto === null) {
                        $encSkipped++;

                        continue;
                    }

                    GameDataRaidEncounter::updateOrCreate(
                        ['id' => $encDto->id],
                        [
                            'raid_instance_id' => $encDto->raidInstanceId,
                            'name' => $encDto->name,
                            'display_order' => $encDto->displayOrder,
                            'creature_display_id' => $encDto->creatureDisplayId,
                            'portrait_url' => $encDto->portraitUrl,
                        ],
                    );
                    $encUpserted++;
                }
            });

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Raid instances synced: {$instUpserted} upserted, {$instSkipped} skipped.");
        $this->info("Raid encounters synced: {$encUpserted} upserted, {$encSkipped} skipped.");
    }

    private function syncMythicKeystoneDungeons(
        BlizzardGameDataClient $client,
        GameDataMythicKeystoneDungeonMapper $mapper,
    ): void {
        $this->info('Syncing mythic-keystone dungeons (current season)...');

        $seasonId = $client->getCurrentMythicPlusSeason();
        $season = $client->getMythicKeystoneSeason($seasonId);
        if ($season === null) {
            $this->warn("Season {$seasonId} payload returned null (404). Falling back to dungeon-index sync.");
            $dungeonIds = [];
        } else {
            $dungeonIds = [];
            foreach ($season['dungeons'] ?? [] as $entry) {
                if (isset($entry['id'])) {
                    $dungeonIds[] = (int) $entry['id'];
                }
            }
        }

        if ($dungeonIds === []) {
            // Fall back: index-driven sync (older expansions where season
            // payload is sparse).
            $index = $client->getMythicKeystoneDungeonIndex();
            if ($index === null) {
                $this->warn('Dungeon index also null. Skipping dungeons.');

                return;
            }
            $dungeonIds = $mapper->extractIndexIds($index);
        }

        $this->info('Will sync '.count($dungeonIds).' dungeons.');

        $bar = $this->output->createProgressBar(count($dungeonIds));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $dungeonIds, &$upserted, &$skipped, $bar) {
            foreach ($dungeonIds as $id) {
                try {
                    $detail = $client->getMythicKeystoneDungeon($id);
                } catch (Throwable $e) {
                    Log::warning("Dungeon sync skipped id={$id}: ".$e->getMessage());
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                // Mythic-keystone dungeon details do not currently expose a
                // `media` block, but the Blizzard API may add one — the mapper
                // already supports it via extractMediaUrl(). Pass null today.
                $dto = $mapper->mapDetail($detail, mediaUrl: null, journalInstanceId: null);
                if ($dto === null) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                GameDataMythicKeystoneDungeon::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'media_url' => $dto->mediaUrl,
                        'journal_instance_id' => $dto->journalInstanceId,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Mythic-keystone dungeons synced: {$upserted} upserted, {$skipped} skipped.");
    }

    private function syncKeystoneAffixes(
        BlizzardGameDataClient $client,
        GameDataKeystoneAffixMapper $mapper,
    ): void {
        $this->info('Syncing keystone affixes...');

        $index = $client->getKeystoneAffixIndex();
        if ($index === null) {
            $this->warn('Keystone-affix index returned null (404). Skipping.');

            return;
        }

        $ids = $mapper->extractIndexIds($index);
        $this->info('Index returned '.count($ids).' affix IDs.');

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($client, $mapper, $ids, &$upserted, &$skipped, $bar) {
            foreach ($ids as $id) {
                try {
                    $detail = $client->getKeystoneAffix($id);
                    $media = $client->getKeystoneAffixMedia($id);
                } catch (Throwable $e) {
                    Log::warning("Affix sync skipped id={$id}: ".$e->getMessage());
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $iconUrl = $mapper->extractIconUrl($media);
                $dto = $mapper->mapDetail($detail, $iconUrl);
                if ($dto === null) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                GameDataKeystoneAffix::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'icon_url' => $dto->iconUrl,
                    ],
                );
                $upserted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Keystone affixes synced: {$upserted} upserted, {$skipped} skipped.");
    }

    /**
     * Sync the talent-tree topology table. For each character spec id, read the
     * spec's detail to find its talent_tree id, then fetch /data/wow/talent-tree/
     * {treeId}/playable-specialization/{specId}, run it through the mapper, and
     * upsert one row keyed by (tree_id, spec_id).
     *
     * Per-pair failure tolerance: log + skip on 404 / thrown error; do not
     * abort the rest of the sync.
     */
    private function syncTalentTrees(
        BlizzardGameDataClient $client,
        GameDataTalentTreeMapper $mapper,
    ): void {
        $this->info('Syncing talent trees...');

        $index = $client->getPlayableSpecializationIndex();
        if ($index === null) {
            $this->warn('Playable-specialization index returned null (404). Skipping talent trees.');

            return;
        }

        $specs = $index['character_specializations'] ?? [];
        $specIds = [];
        foreach ($specs as $entry) {
            if (isset($entry['id'])) {
                $specIds[] = (int) $entry['id'];
            }
        }

        $this->info('Index returned '.count($specIds).' character spec IDs.');

        $bar = $this->output->createProgressBar(count($specIds));
        $bar->start();

        $upserted = 0;
        $skipped = 0;

        foreach ($specIds as $specId) {
            try {
                $specDetail = $client->getPlayableSpecialization($specId);
                $treeId = isset($specDetail['talent_tree']['id'])
                    ? (int) $specDetail['talent_tree']['id']
                    : null;

                if ($treeId === null) {
                    Log::warning("Talent-tree sync skipped specId={$specId}: no talent_tree on spec detail");
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $treeRaw = $client->getTalentTree($treeId, $specId);
                $dto = $mapper->mapDetail($treeRaw);
                if ($dto === null) {
                    Log::warning("Talent-tree sync skipped specId={$specId} treeId={$treeId}: mapper returned null");
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                DB::transaction(function () use ($dto, &$upserted) {
                    GameDataTalentTree::updateOrCreate(
                        ['tree_id' => $dto->treeId, 'spec_id' => $dto->specId],
                        [
                            'name' => $dto->name,
                            'tree' => $dto->tree,
                            'synced_at' => now(),
                        ],
                    );
                    $upserted++;
                });
            } catch (Throwable $e) {
                Log::warning("Talent-tree sync skipped specId={$specId}: ".$e->getMessage());
                $skipped++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Talent trees synced: {$upserted} upserted, {$skipped} skipped.");
    }
}
