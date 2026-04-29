<?php

declare(strict_types=1);

namespace App\Blizzard\DTO;

final readonly class CharacterStats
{
    /**
     * Full normalized stats payload, indexed by Blizzard field name.
     *
     * Common fields (not exhaustive — Blizzard adds/changes these per patch):
     *   health, power, power_type, strength, agility, intellect, stamina,
     *   melee_crit, melee_haste, mastery, bonus_armor, lifesteal, versatility,
     *   versatility_damage_done_bonus, versatility_healing_done_bonus,
     *   versatility_damage_taken_bonus, avoidance, attack_power,
     *   main_hand_damage_min, main_hand_damage_max, main_hand_speed, main_hand_dps,
     *   off_hand_damage_min, off_hand_damage_max, off_hand_speed, off_hand_dps,
     *   spell_power, spell_penetration, spell_crit, mana_regen, mana_regen_combat,
     *   armor, dodge, parry, block, ranged_crit, ranged_haste, etc.
     *
     * Each entry is either a scalar (int / float) or an object of the form:
     *   { value: number, effective: number, rating: int, rating_bonus: float }
     *
     * The FE picks which fields to render; the BE does not enforce a schema.
     *
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public array $fields,
        public ?int $health = null,
        public ?int $power = null,
        public ?string $powerType = null,
        public ?int $strength = null,
        public ?int $agility = null,
        public ?int $intellect = null,
        public ?int $stamina = null,
    ) {}
}
