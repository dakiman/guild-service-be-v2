<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\GameDataConnectedRealm;
use App\Models\RealmSlugMap;
use App\Services\Ranks\RealmSlugMapBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealmSlugMapBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_flattens_connected_realm_groups_and_replaces_old_rows(): void
    {
        RealmSlugMap::create(['region' => 'eu', 'realm_slug' => 'stale', 'connected_realm_id' => 1]);
        GameDataConnectedRealm::create(['region' => 'eu', 'connected_realm_id' => 1403, 'realm_slugs' => ['draenor']]);
        GameDataConnectedRealm::create(['region' => 'eu', 'connected_realm_id' => 1084, 'realm_slugs' => ['tarren-mill', 'dentarg']]);
        GameDataConnectedRealm::create(['region' => 'us', 'connected_realm_id' => 57, 'realm_slugs' => ['illidan']]);

        $written = app(RealmSlugMapBuilder::class)->rebuild();

        $this->assertSame(4, $written);
        $this->assertSame(4, RealmSlugMap::count());
        $this->assertSame(1084, RealmSlugMap::where('region', 'eu')->where('realm_slug', 'dentarg')->value('connected_realm_id'));
        $this->assertNull(RealmSlugMap::where('realm_slug', 'stale')->first());
    }
}
