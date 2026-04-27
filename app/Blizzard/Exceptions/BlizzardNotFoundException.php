<?php

declare(strict_types=1);

namespace App\Blizzard\Exceptions;

use RuntimeException;

/**
 * Thrown by BlizzardProfileClient when Blizzard returns 404 for a
 * character or guild basic profile. Distinguishes "entity does not
 * exist" from network/auth/5xx errors so sync jobs can write a
 * not-found cache marker instead of treating it as a transient failure.
 */
class BlizzardNotFoundException extends RuntimeException {}
