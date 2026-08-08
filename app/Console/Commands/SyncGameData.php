<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blizzard\Client\BlizzardGameDataClient;
use App\Blizzard\Client\GameDataClientFactory;
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
use App\Models\GameDataConnectedRealm;
use App\Models\GameDataFaction;
use App\Models\GameDataKeystoneAffix;
use App\Models\GameDataMount;
use App\Models\GameDataMythicKeystoneDungeon;
use App\Models\GameDataPeriod;
use App\Models\GameDataRaidEncounter;
use App\Models\GameDataRaidInstance;
use App\Models\GameDataTalentTree;
use App\Models\GameDataTitle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGameData extends Command
{
    protected $signature = 'blizzard:sync-game-data {resource? : factions|titles|mounts|achievements|pve|talent-trees|periods|connected-realms; omit for all}';

    protected $description = 'Sync static reference data (factions/titles/mounts/achievements/pve/talent-trees/periods/connected-realms) from Blizzard Game Data API into game_data_* tables';

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
        GameDataClientFactory $clientFactory,
    ): int {
        $resource = $this->argument('resource');

        $resources = $resource === null
            ? ['factions', 'titles', 'mounts', 'achievements', 'pve', 'talent-trees', 'periods', 'connected-realms']
            : [$resource];

        $achievementsEnabled = (bool) config('blizzard.sync.achievements_enabled');

        foreach ($resources as $r) {
            if ($r === 'achievements' && ! $achievementsEnabled) {
                $this->warn('Achievements game-data sync skipped: BLIZZARD_SYNC_ACHIEVEMENTS_ENABLED is off.');

                continue;
            }

            match ($r) {
                'factions' => $this->syncFactions($client, $factionMapper),
                'titles' => $this->syncTitles($client, $titleMapper),
                'mounts' => $this->syncMounts($client, $mountMapper),
                'achievements' => $this->syncAchievements($client, $achievementCategoryMapper, $achievementMapper),
                'pve' => $this->syncPve($client, $raidInstanceMapper, $raidEncounterMapper, $dungeonMapper, $affixMapper),
                'talent-trees' => $this->syncTalentTrees($client, $talentTreeMapper),
                'periods' => $this->syncPeriods($clientFactory),
                'connected-realms' => $this->syncConnectedRealms($clientFactory),
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

        $skipped = 0;

        // Fetch all detail OUTSIDE any transaction — never hold a DB transaction
        // open across hundreds of sequential HTTP calls. (P2.4)
        $dtos = [];
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

            $dtos[] = $dto;
            $bar->advance();
        }

        DB::transaction(function () use ($dtos) {
            foreach ($dtos as $dto) {
                GameDataFaction::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'parent_faction_id' => $dto->parentFactionId,
                        'expansion_id' => $dto->expansionId,
                    ],
                );
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Factions synced: '.count($dtos)." upserted, {$skipped} skipped.");
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

        $skipped = 0;

        // Fetch detail outside the transaction (P2.4).
        $dtos = [];
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

            $dtos[] = $dto;
            $bar->advance();
        }

        DB::transaction(function () use ($dtos) {
            foreach ($dtos as $dto) {
                GameDataTitle::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name_male' => $dto->nameMale,
                        'name_female' => $dto->nameFemale,
                    ],
                );
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Titles synced: '.count($dtos)." upserted, {$skipped} skipped.");
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

        $skipped = 0;

        // Fetch detail outside the transaction (P2.4).
        $dtos = [];
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

            $dtos[] = $dto;
            $bar->advance();
        }

        DB::transaction(function () use ($dtos) {
            foreach ($dtos as $dto) {
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
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Mounts synced: '.count($dtos)." upserted, {$skipped} skipped.");
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
        $catSkipped = 0;

        // Fetch category detail outside the transaction (P2.4).
        $catDtos = [];
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

            $catDtos[] = $dto;
            $bar->advance();
        }

        DB::transaction(function () use ($catDtos) {
            foreach ($catDtos as $dto) {
                GameDataAchievementCategory::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'parent_id' => $dto->parentId,
                        'display_order' => $dto->displayOrder,
                    ],
                );
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Categories synced: '.count($catDtos)." upserted, {$catSkipped} skipped.");

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

        // The public /game-data/mythic-keystone-dungeons endpoint caches
        // dungeons + affixes together (affixes ride along in the same
        // response) — invalidate once here, after both slices have synced,
        // so a re-sync of either never serves a stale payload for up to 1h.
        Cache::forget('game-data:mythic-keystone-dungeons');
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

            // Fetch this instance's encounter detail (+creature-display media)
            // OUTSIDE any transaction — never hold a DB transaction open across
            // sequential HTTP calls. (P2.4)
            $encounterDtos = [];
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

                $encounterDtos[] = $encDto;
            }

            // Short per-instance transaction: only the upserts. Keeps the
            // per-instance transaction shape (one tx per raid) — see slice docs.
            DB::transaction(function () use (
                $instanceDto,
                $encounterDtos,
                &$instUpserted,
                &$encUpserted,
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

                foreach ($encounterDtos as $encDto) {
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

        // Fetch all dungeon detail OUTSIDE any transaction. (P2.4)
        $dtos = [];
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

            $dtos[] = $dto;
            $bar->advance();
        }

        DB::transaction(function () use ($dtos, &$upserted) {
            foreach ($dtos as $dto) {
                $attributes = [
                    'name' => $dto->name,
                    'keystone_upgrades' => $dto->keystoneUpgrades,
                    'journal_instance_id' => $dto->journalInstanceId,
                ];
                // Blizzard emits no media doc for keystone dungeons (mediaUrl
                // is null today) — icons come from dungeons:backfill-icons-
                // from-raiderio. Never let a null overwrite a backfilled icon.
                if ($dto->mediaUrl !== null) {
                    $attributes['media_url'] = $dto->mediaUrl;
                }

                GameDataMythicKeystoneDungeon::updateOrCreate(['id' => $dto->id], $attributes);
                $upserted++;
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

        // Fetch all affix detail + media OUTSIDE any transaction. (P2.4)
        $dtos = [];
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

            $dtos[] = $dto;
            $bar->advance();
        }

        DB::transaction(function () use ($dtos, &$upserted) {
            foreach ($dtos as $dto) {
                GameDataKeystoneAffix::updateOrCreate(
                    ['id' => $dto->id],
                    [
                        'name' => $dto->name,
                        'icon_url' => $dto->iconUrl,
                    ],
                );
                $upserted++;
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
                // Spec detail carries `spec_talent_tree.key.href` like
                //   /data/wow/talent-tree/852/playable-specialization/261
                // — there is no top-level `talent_tree.id`. Parse the tree id
                // out of the href, mirroring CharacterSpecializationMapper's
                // pattern.
                $href = $specDetail['spec_talent_tree']['key']['href'] ?? null;
                $treeId = (is_string($href) && preg_match('#/talent-tree/(\d+)#', $href, $m) === 1)
                    ? (int) $m[1]
                    : null;

                if ($treeId === null) {
                    Log::warning("Talent-tree sync skipped specId={$specId}: no spec_talent_tree href on spec detail");
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

    /**
     * Sync the mythic-keystone period registry per ladder region. Periods live
     * in the `dynamic-{region}` namespace and differ per region, so each region
     * gets its own client from the factory.
     *
     * Both this and syncConnectedRealms deal in tiny row counts, so they skip
     * the fetch-then-transaction dance the big slices use — per-row
     * updateOrCreate keeps every HTTP call outside a transaction. (P2.4)
     */
    private function syncPeriods(GameDataClientFactory $clientFactory): void
    {
        foreach (config('blizzard.mplus_leaderboard.regions', ['eu', 'us']) as $region) {
            $client = $clientFactory->forRegion($region);
            $index = $client->getMythicKeystonePeriodIndex();
            if ($index === null) {
                $this->warn("Period index returned null (404) for {$region}. Skipping.");

                continue;
            }

            // Only the tail matters: old periods are immutable and never queried.
            $recent = collect($index['periods'] ?? [])->pluck('id')->filter()->sort()->values()->slice(-12);
            $known = GameDataPeriod::query()->where('region', $region)->whereIn('period_id', $recent)->pluck('period_id');
            $missing = $recent->diff($known)->values();

            $upserted = 0;
            foreach ($missing as $id) {
                $detail = $client->getMythicKeystonePeriod((int) $id);
                if ($detail === null) {
                    continue;
                }

                GameDataPeriod::updateOrCreate(
                    ['region' => $region, 'period_id' => (int) $id],
                    [
                        'start_at' => isset($detail['start_timestamp']) ? Carbon::createFromTimestampMs($detail['start_timestamp']) : null,
                        'end_at' => isset($detail['end_timestamp']) ? Carbon::createFromTimestampMs($detail['end_timestamp']) : null,
                    ],
                );
                $upserted++;
            }

            $this->info("Periods {$region}: {$upserted} upserted, ".($recent->count() - $missing->count()).' already known.');
        }
    }

    /**
     * Sync the connected-realm roster per ladder region. The index only carries
     * hrefs (no ids), so the connected-realm id is parsed out of the href; the
     * detail call supplies the member realm slugs.
     */
    private function syncConnectedRealms(GameDataClientFactory $clientFactory): void
    {
        foreach (config('blizzard.mplus_leaderboard.regions', ['eu', 'us']) as $region) {
            $client = $clientFactory->forRegion($region);
            $index = $client->getConnectedRealmIndex();
            if ($index === null) {
                $this->warn("Connected-realm index returned null (404) for {$region}. Skipping.");

                continue;
            }

            $ids = collect($index['connected_realms'] ?? [])
                ->map(fn ($entry) => preg_match('#/connected-realm/(\d+)#', $entry['href'] ?? '', $m) ? (int) $m[1] : null)
                ->filter()
                ->values();

            $upserted = 0;
            foreach ($ids as $id) {
                $detail = $client->getConnectedRealm($id);
                $slugs = collect($detail['realms'] ?? [])->pluck('slug')->filter()->values()->all();

                GameDataConnectedRealm::updateOrCreate(
                    ['region' => $region, 'connected_realm_id' => $id],
                    ['realm_slugs' => $slugs],
                );
                $upserted++;
            }

            $this->info("Connected realms {$region}: {$upserted} upserted.");
        }
    }
}
