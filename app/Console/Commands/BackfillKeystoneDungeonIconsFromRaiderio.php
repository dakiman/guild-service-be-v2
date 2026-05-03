<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GameDataMythicKeystoneDungeon;
use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Console\Command;

class BackfillKeystoneDungeonIconsFromRaiderio extends Command
{
    /**
     * Default expansion 11 = Midnight (current as of MN-1). Override with
     * --expansion=N when a new expansion ships, or run for several expansions
     * to cover historical dungeons referenced by older runs.
     */
    protected $signature = 'dungeons:backfill-icons-from-raiderio {--expansion=11 : raider.io expansion_id to fetch}';

    protected $description = 'Pull dungeon icon_url from raider.io and write into game_data_mythic_keystone_dungeons.media_url';

    public function handle(RaiderIOClient $client): int
    {
        $expansionId = (int) $this->option('expansion');

        $this->info("Fetching raider.io static-data for expansion_id={$expansionId}…");
        $payload = $client->mythicPlusStaticData($expansionId);

        $iconByChallengeModeId = [];
        foreach ($payload['seasons'] ?? [] as $season) {
            foreach ($season['dungeons'] ?? [] as $dungeon) {
                $id = $dungeon['challenge_mode_id'] ?? null;
                $icon = $dungeon['icon_url'] ?? null;
                if (is_int($id) && is_string($icon) && $icon !== '') {
                    // Last write wins; same dungeon across seasons typically has the same icon.
                    $iconByChallengeModeId[$id] = $icon;
                }
            }
        }

        if ($iconByChallengeModeId === []) {
            $this->error('No dungeons with icon_url found in the raider.io response.');
            return self::FAILURE;
        }

        $this->info(sprintf('Found %d distinct (challenge_mode_id → icon_url) pairs.', count($iconByChallengeModeId)));

        $matched = 0;
        $updated = 0;
        $missing = [];

        foreach ($iconByChallengeModeId as $id => $iconUrl) {
            $dungeon = GameDataMythicKeystoneDungeon::find($id);
            if ($dungeon === null) {
                $missing[] = $id;
                continue;
            }
            $matched++;
            if ($dungeon->media_url !== $iconUrl) {
                $dungeon->media_url = $iconUrl;
                $dungeon->save();
                $updated++;
            }
        }

        $this->info(sprintf('Matched %d / %d to existing DB rows.', $matched, count($iconByChallengeModeId)));
        $this->info(sprintf('Updated %d row(s).', $updated));

        if ($missing !== []) {
            $this->warn(sprintf('No DB row for %d challenge_mode_id(s): %s', count($missing), implode(', ', $missing)));
        }

        return self::SUCCESS;
    }
}
