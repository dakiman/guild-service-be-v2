<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RaiderIO;

use App\Services\RaiderIO\DTO\SeedCharacterRef;
use App\Services\RaiderIO\DTO\SeedRunRef;
use App\Services\RaiderIO\RaiderIOClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RaiderIOClientRunsTest extends TestCase
{
    public function test_top_runs_yields_run_refs_with_members(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu.json')), true);
        Http::fake([
            'raider.io/api/v1/mythic-plus/runs*' => Http::response($fixture, 200),
        ]);

        $client = app(RaiderIOClient::class);

        $runs = iterator_to_array($client->topRuns('eu', 'season-mn-1', 1), preserve_keys: false);

        $this->assertCount(3, $runs);
        $this->assertInstanceOf(SeedRunRef::class, $runs[0]);
        $this->assertSame(1001, $runs[0]->keystoneRunId);
        $this->assertSame('eu', $runs[0]->region);
        $this->assertCount(5, $runs[0]->members);
        $this->assertInstanceOf(SeedCharacterRef::class, $runs[0]->members[0]);
        $this->assertSame('Alice', $runs[0]->members[0]->name);
        $this->assertSame('tarren-mill', $runs[0]->members[0]->realmSlug);
        $this->assertSame('eu', $runs[0]->members[0]->region);
        $this->assertSame(1003, $runs[2]->keystoneRunId);
    }

    public function test_top_runs_paginates_pages_until_pages_count_or_empty(): void
    {
        $page0 = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu.json')), true);
        $page1 = json_decode(file_get_contents(base_path('tests/fixtures/raiderio/top-runs-eu-page-2.json')), true);
        $emptyPage = ['rankings' => []];

        Http::fake(function ($request) use ($page0, $page1, $emptyPage) {
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?? '', $q);
            $page = (int) ($q['page'] ?? 0);

            return match ($page) {
                0 => Http::response($page0, 200),
                1 => Http::response($page1, 200),
                default => Http::response($emptyPage, 200),
            };
        });

        $client = app(\App\Services\RaiderIO\RaiderIOClient::class);

        // Request 3 pages — page 0 = 3 runs, page 1 = 1 run, page 2 returns empty (generator stops).
        $runs = iterator_to_array($client->topRuns('eu', 'season-mn-1', 3), preserve_keys: false);

        $this->assertCount(4, $runs); // 3 + 1
        Http::assertSentCount(3); // pages 0, 1, 2
    }

    public function test_top_runs_stops_immediately_on_first_empty_page(): void
    {
        Http::fake(['raider.io/*' => Http::response(['rankings' => []], 200)]);

        $client = app(\App\Services\RaiderIO\RaiderIOClient::class);

        $runs = iterator_to_array($client->topRuns('eu', 'season-mn-1', 5), preserve_keys: false);

        $this->assertCount(0, $runs);
        Http::assertSentCount(1);
    }
}
