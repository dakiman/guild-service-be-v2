<?php

declare(strict_types=1);

namespace App\Blizzard\Client;

use Illuminate\Support\Facades\Cache;

class BlizzardGameDataClient extends BlizzardClient
{
    protected function namespace(): string
    {
        return "dynamic-{$this->region}";
    }

    protected function timeout(): int
    {
        return 30;
    }

    public function getCurrentMythicPlusSeason(): int
    {
        $override = config('blizzard.mythic_plus.season_override');

        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        return (int) Cache::remember('blizzard:mythic-plus:current-season', 86400, function () {
            $response = $this->request()
                ->get('/data/wow/mythic-keystone/season/index');

            $response->throw();

            $data = $response->json();
            $seasons = $data['seasons'] ?? [];
            $lastSeason = end($seasons);

            return (int) $lastSeason['id'];
        });
    }
}
