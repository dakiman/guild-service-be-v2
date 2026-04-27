<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by Character/Guild services when a not-found cache marker is
 * present, signalling the entity has been confirmed missing on Blizzard
 * within the configured TTL. Controllers translate this to HTTP 404.
 */
class EntityNotFoundException extends RuntimeException {}
