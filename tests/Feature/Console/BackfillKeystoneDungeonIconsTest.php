<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\GameDataMythicKeystoneDungeon;
use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillKeystoneDungeonIconsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/peon-icons-'.uniqid();

        GameDataMythicKeystoneDungeon::create([
            'id' => 584,
            'name' => 'The Blinding Vale',
            'media_url' => null,
            'keystone_upgrades' => null,
            'journal_instance_id' => null,
        ]);

        $client = $this->createStub(RaiderIOClient::class);
        $client->method('mythicPlusStaticData')->willReturn([
            'seasons' => [[
                'slug' => 'season-mn-2',
                'dungeons' => [
                    ['challenge_mode_id' => 584, 'icon_url' => 'https://cdn.example.test/584.jpg'],
                ],
            ]],
        ]);
        $this->app->instance(RaiderIOClient::class, $client);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * Regression: the MN-2 rollover (2026-08-22) ran inside the app container,
     * where `../frontend/public` does not exist. The command mkdir'd a dungeons
     * dir in the container's ephemeral filesystem, downloaded the icons there,
     * and pointed media_url at /dungeons/{id}.jpg — which then 404'd on the FE.
     * When the frontend tree isn't available, the command must abort before
     * touching the DB.
     */
    public function test_aborts_without_touching_db_when_destination_parent_is_missing(): void
    {
        Http::fake();

        $dest = $this->root.'/frontend/public/dungeons';

        $this->artisan('dungeons:backfill-icons-from-raiderio', ['--dest' => $dest])
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertDirectoryDoesNotExist($dest);
        $this->assertNull(GameDataMythicKeystoneDungeon::find(584)->media_url);
    }

    public function test_downloads_icon_and_stores_local_path(): void
    {
        Http::fake(['cdn.example.test/*' => Http::response('jpeg-bytes', 200)]);

        $dest = $this->root.'/frontend/public/dungeons';
        mkdir(dirname($dest), 0755, true);

        $this->artisan('dungeons:backfill-icons-from-raiderio', ['--dest' => $dest])
            ->assertSuccessful();

        $this->assertFileExists($dest.'/584.jpg');
        $this->assertSame('jpeg-bytes', file_get_contents($dest.'/584.jpg'));
        $this->assertSame('/dungeons/584.jpg', GameDataMythicKeystoneDungeon::find(584)->media_url);
    }
}
