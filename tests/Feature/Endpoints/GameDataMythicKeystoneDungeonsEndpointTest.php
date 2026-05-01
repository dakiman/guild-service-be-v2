<?php

declare(strict_types=1);

namespace Tests\Feature\Endpoints;

use App\Models\GameDataKeystoneAffix;
use App\Models\GameDataMythicKeystoneDungeon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameDataMythicKeystoneDungeonsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixtures(): void
    {
        GameDataMythicKeystoneDungeon::create([
            'id' => 503,
            'name' => 'Ara-Kara, City of Echoes',
            'media_url' => 'https://example/arak.jpg',
            'journal_instance_id' => 1271,
        ]);
        GameDataMythicKeystoneDungeon::create([
            'id' => 504,
            'name' => 'City of Threads',
            'media_url' => null,
            'journal_instance_id' => null,
        ]);

        GameDataKeystoneAffix::create([
            'id' => 9,
            'name' => 'Tyrannical',
            'icon_url' => 'https://example/affix-9.jpg',
        ]);
        GameDataKeystoneAffix::create([
            'id' => 10,
            'name' => 'Fortified',
            'icon_url' => 'https://example/affix-10.jpg',
        ]);
    }

    public function test_returns_dungeons_and_affixes_in_one_payload(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons?season=current');

        $response->assertOk();
        $response->assertJsonCount(2, 'dungeons');

        // Dungeons sorted by name ascending.
        $response->assertJsonPath('dungeons.0.id', 503);
        $response->assertJsonPath('dungeons.0.name', 'Ara-Kara, City of Echoes');
        $response->assertJsonPath('dungeons.0.media_url', 'https://example/arak.jpg');
        $response->assertJsonPath('dungeons.0.journal_instance_id', 1271);

        $response->assertJsonPath('dungeons.1.id', 504);
        $response->assertJsonPath('dungeons.1.media_url', null);
        $response->assertJsonPath('dungeons.1.journal_instance_id', null);

        // Affixes is a dictionary keyed by id (FE does O(1) lookup via affixes[id]).
        $response->assertJsonPath('affixes.9.id', 9);
        $response->assertJsonPath('affixes.9.name', 'Tyrannical');
        $response->assertJsonPath('affixes.9.icon_url', 'https://example/affix-9.jpg');
        $response->assertJsonPath('affixes.10.id', 10);
        $response->assertJsonPath('affixes.10.name', 'Fortified');

        // Season metadata: null today (deferred — see controller).
        $response->assertJsonPath('season', null);
    }

    public function test_default_no_query_string_works(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons');

        $response->assertOk();
        $response->assertJsonCount(2, 'dungeons');
    }

    public function test_response_carries_cache_control_header(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons?season=current');

        // Symfony normalizes Cache-Control directives alphabetically.
        $response->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_returns_empty_arrays_when_tables_empty(): void
    {
        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons?season=current');

        $response->assertOk();
        // Top-level keys present with empty containers + null season. We don't
        // use assertExactJson here because PHP json_decode normalizes the
        // empty `{}` affixes dict to `[]`, hiding the JSON-level distinction.
        $response->assertJsonStructure(['dungeons', 'affixes', 'season']);
        $response->assertJsonCount(0, 'dungeons');
        $response->assertJsonCount(0, 'affixes');
        $response->assertJsonPath('season', null);

        // Confirm affixes is encoded as a JSON object, not an array, even
        // when empty — so the FE's `Record<number, ...>` typing is correct.
        $this->assertStringContainsString('"affixes":{}', $response->getContent());
    }

    public function test_endpoint_is_public_no_auth(): void
    {
        $this->seedFixtures();

        $response = $this->getJson('/api/v1/game-data/mythic-keystone-dungeons?season=current');

        $response->assertOk();
    }
}
