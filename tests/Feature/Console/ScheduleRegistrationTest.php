<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduleRegistrationTest extends TestCase
{
    public function test_raiderio_seed_all_phases_is_scheduled_daily_at_0100(): void
    {
        // withSchedule()'s callback is wired via Artisan::starting(), which only
        // fires once Laravel's console Application is actually constructed.
        // Resolving Schedule::class directly (with no artisan command ever run)
        // yields an empty schedule regardless of bootstrap/app.php, so a no-op
        // console call is needed to trigger registration before inspecting events.
        Artisan::call('list');

        $events = collect(app(Schedule::class)->events());

        $event = $events->first(
            fn ($e) => str_contains((string) $e->command, 'raiderio:seed')
                && str_contains((string) $e->command, '--phase=all')
        );

        $this->assertNotNull($event, 'raiderio:seed --phase=all is not scheduled');
        $this->assertSame('0 1 * * *', $event->expression);
    }
}
