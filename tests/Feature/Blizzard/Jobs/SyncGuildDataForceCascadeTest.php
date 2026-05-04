<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard\Jobs;

use App\Blizzard\Jobs\SyncGuildData;
use Tests\TestCase;

class SyncGuildDataForceCascadeTest extends TestCase
{
    public function test_force_cascade_constructor_param_defaults_false(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'Echo');

        $this->assertFalse($job->forceCascade);
    }

    public function test_force_cascade_can_be_set_true(): void
    {
        $job = new SyncGuildData(region: 'eu', realm: 'tarren-mill', name: 'Echo', forceCascade: true);

        $this->assertTrue($job->forceCascade);
    }
}
