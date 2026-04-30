<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GameDataExpansion;
use Illuminate\Database\Seeder;

class GameDataExpansionSeeder extends Seeder
{
    /**
     * Static expansion list, ordered newest-first.
     *
     * Source of truth: WoW expansion release timeline. Add a row each new
     * expansion and update the ordinal of older expansions if the FE renders
     * by reverse-chronological-order (it does — see ReputationsList.vue).
     */
    private const EXPANSIONS = [
        ['id' => 1, 'name' => 'The War Within', 'display_order' => 1],
        ['id' => 2, 'name' => 'Dragonflight', 'display_order' => 2],
        ['id' => 3, 'name' => 'Shadowlands', 'display_order' => 3],
        ['id' => 4, 'name' => 'Battle for Azeroth', 'display_order' => 4],
        ['id' => 5, 'name' => 'Legion', 'display_order' => 5],
        ['id' => 6, 'name' => 'Warlords of Draenor', 'display_order' => 6],
        ['id' => 7, 'name' => 'Mists of Pandaria', 'display_order' => 7],
        ['id' => 8, 'name' => 'Cataclysm', 'display_order' => 8],
        ['id' => 9, 'name' => 'Wrath of the Lich King', 'display_order' => 9],
        ['id' => 10, 'name' => 'The Burning Crusade', 'display_order' => 10],
        ['id' => 11, 'name' => 'Classic', 'display_order' => 11],
    ];

    public function run(): void
    {
        foreach (self::EXPANSIONS as $row) {
            GameDataExpansion::updateOrCreate(['id' => $row['id']], $row);
        }
    }
}
