<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SpecClasses;
use App\Support\SpecRoles;
use PHPUnit\Framework\TestCase;

class SpecClassesTest extends TestCase
{
    public function test_every_spec_with_a_role_has_a_class(): void
    {
        foreach (array_keys(SpecRoles::ROLES) as $specId) {
            $this->assertNotNull(SpecClasses::classFor($specId), "spec {$specId} has no class");
        }
    }

    public function test_known_mappings(): void
    {
        $this->assertSame(12, SpecClasses::classFor(581));  // Vengeance → Demon Hunter
        $this->assertSame(9, SpecClasses::classFor(267));   // Destruction → Warlock
        $this->assertNull(SpecClasses::classFor(null));
        $this->assertNull(SpecClasses::classFor(999999));
    }
}
