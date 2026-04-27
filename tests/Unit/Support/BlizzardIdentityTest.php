<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BlizzardIdentity;
use Tests\TestCase;

class BlizzardIdentityTest extends TestCase
{
    public function test_realm_lowercases_and_replaces_spaces_with_hyphens(): void
    {
        $this->assertSame('the-maelstrom', BlizzardIdentity::realm('The Maelstrom'));
        $this->assertSame('blades-edge', BlizzardIdentity::realm('Blades Edge'));
    }

    public function test_realm_collapses_runs_of_whitespace_and_trims(): void
    {
        $this->assertSame('blades-edge', BlizzardIdentity::realm('  blades  edge  '));
    }

    public function test_realm_is_idempotent_on_already_slugified_input(): void
    {
        $this->assertSame('the-maelstrom', BlizzardIdentity::realm('the-maelstrom'));
    }

    public function test_name_lowercases_ascii(): void
    {
        $this->assertSame('cirna', BlizzardIdentity::name('Cirna'));
        $this->assertSame('leonardmccoy', BlizzardIdentity::name('LeonardMcCoy'));
    }

    public function test_name_preserves_utf8(): void
    {
        $this->assertSame('élise', BlizzardIdentity::name('Élise'));
        $this->assertSame('łukasz', BlizzardIdentity::name('Łukasz'));
    }

    public function test_name_trims_whitespace(): void
    {
        $this->assertSame('cirna', BlizzardIdentity::name('  cirna  '));
    }
}
