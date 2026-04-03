<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Character;
use App\Models\User;

class CharacterPolicy
{
    public function toggleRecruitment(User $user, Character $character): bool
    {
        return $user->id === $character->user_id;
    }
}
