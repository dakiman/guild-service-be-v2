<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Canonicalize Blizzard API path segments.
 *
 * Realms are ASCII (Blizzard's realm catalog has no UTF-8 entries) so
 * Str::slug is correct. Character / guild names support UTF-8 and must
 * be lowercased without ASCII transliteration.
 */
final class BlizzardIdentity
{
    public static function realm(string $realm): string
    {
        return Str::slug($realm);
    }

    public static function name(string $name): string
    {
        return Str::lower(trim($name));
    }
}
