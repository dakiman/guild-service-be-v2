# Backend Review Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the six confirmed fixes from the May 4 backend review: email-enumeration in password reset, frontend reset URL, guild roster pagination clamp, Blizzard OAuth state + redirect-URI allowlist, missing public-route throttles + region constraints, Sanctum token expiration, and two pieces of doc drift.

**Architecture:** Narrow, surgical changes at the API boundary. No refactoring of unaffected code. TDD where the change has a clear behavior assertion (controllers, validation, route policy); doc-only tasks have no tests.

**Tech Stack:** Laravel 13, PHP 8.4, Sanctum bearer tokens, Cache (Redis), PHPUnit Feature/Unit, Pint.

**Out of scope (verified during review and dropped):**
- Battle.net character ownership guard — `userId` originates from a successful BNet OAuth exchange, so cross-user reassignment isn't reachable; a guard would also block legitimate BNet account migrations.
- `X-Data-Staleness` header refactor — header is already set in `CharacterController::show` and `GuildController::show`; the proposed `hasStaleSlices()` extraction is a no-op cosmetic.
- CORS `exposed_headers` — FE consumes the API same-origin via the nginx proxy on port 8092; CORS doesn't apply.
- Recruitment PATCH response shape — current shape works; FE depends on it; no bug.
- `RAIDERIO_SEED_CHAR_TTL` doc drift — claim was invented; CLAUDE.md never referenced this var.

---

## File Structure

Backend files that change:

- `app/Http/Controllers/Auth/ForgotPasswordController.php` — drop `exists` rule, return constant message, drop dead URL-building code.
- `app/Providers/AppServiceProvider.php` — register `ResetPassword::createUrlUsing()` so reset emails point at the SPA.
- `app/Http/Controllers/GuildController.php` — clamp `per_page`, validate `filter` length.
- `app/Http/Requests/BlizzardOAuthRequest.php` — require `state`, allowlist `redirectUri`.
- `app/Http/Requests/BlizzardOAuthStateRequest.php` — **new** — validates state-mint input.
- `app/Http/Controllers/BlizzardController.php` — add `state()` action; consume cached state in `handleCode()`.
- `routes/api.php` — add `/blizzard-oauth/state` route; add region constraints + throttles to lookup routes; throttle auth routes.
- `config/blizzard.php` — add `oauth.redirect_uris` allowlist + `oauth.state_ttl`.
- `config/sanctum.php` — env-driven token expiration.
- `.env.example` — `BLIZZARD_OAUTH_REDIRECT_URIS`, `BLIZZARD_OAUTH_STATE_TTL`, `SANCTUM_TOKEN_EXPIRATION_MINUTES`.
- `CLAUDE.md` — fix `BLIZZARD_CRAWL_RECENT_THRESHOLD` value (6h → 3d).
- `README.md` — add missing endpoints to the listing.

New tests:

- `tests/Feature/Auth/PasswordResetEndpointTest.php`
- `tests/Feature/Http/GuildControllerPaginationTest.php`
- `tests/Feature/Blizzard/OAuthControllerTest.php`
- `tests/Feature/Http/PublicRouteThrottleTest.php`

Frontend coordination (not in this plan):

- The FE's Battle.net OAuth flow must call `POST /api/v1/{region}/blizzard-oauth/state` first, then send the returned `state` back in `POST /api/v1/{region}/blizzard-oauth`. **Coordinate Task 4 with the matching FE PR.**

---

### Task 1: Stop email enumeration in password-reset and route reset link to the SPA

**Files:**
- Modify: `app/Http/Controllers/Auth/ForgotPasswordController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Auth/PasswordResetEndpointTest.php`

- [ ] **Step 1: Write failing test for enumeration + frontend URL**

Create `tests/Feature/Auth/PasswordResetEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_email_returns_same_message_and_sends_no_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'missing@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'If that email exists, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_known_email_returns_same_message_and_sends_reset_with_frontend_url(): void
    {
        Notification::fake();
        config()->set('app.frontend_url', 'http://localhost:8092');

        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'known@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'If that email exists, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl ?? '';

            return str_starts_with($url, 'http://localhost:8092/reset-password?')
                && str_contains($url, 'token=')
                && str_contains($url, 'email=known%40example.com');
        });
    }

    public function test_invalid_email_format_still_validates(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'not-an-email',
        ])->assertUnprocessable();
    }
}
```

- [ ] **Step 2: Run the test and confirm failure**

```bash
docker compose exec app composer test -- --filter=PasswordResetEndpointTest
```

Expected: `test_unknown_email_returns_same_message_and_sends_no_notification` fails (currently returns 422 from the `exists` rule). The frontend-URL test fails because `ResetPassword::createUrlUsing` is unset.

- [ ] **Step 3: Drop `exists` rule and dead URL-building code**

Replace the body of `app/Http/Controllers/Auth/ForgotPasswordController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If that email exists, a password reset link has been sent.',
        ]);
    }
}
```

(Removes the `exists:users,email` rule, the unused `$url` line, and the redundant inner `sendPasswordResetNotification` call.)

- [ ] **Step 4: Register frontend reset URL generator**

Edit `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode((string) $notifiable->getEmailForPasswordReset());
        });
    }
}
```

- [ ] **Step 5: Run the test and confirm green**

```bash
docker compose exec app composer test -- --filter=PasswordResetEndpointTest
```

Expected: all three tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Auth/ForgotPasswordController.php \
        app/Providers/AppServiceProvider.php \
        tests/Feature/Auth/PasswordResetEndpointTest.php
git commit -m "fix(auth): close password-reset enumeration and route link to SPA"
```

---

### Task 2: Clamp guild-roster pagination and validate filter length

**Files:**
- Modify: `app/Http/Controllers/GuildController.php`
- Test: `tests/Feature/Http/GuildControllerPaginationTest.php`

- [ ] **Step 1: Write failing pagination + filter tests**

Create `tests/Feature/Http/GuildControllerPaginationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Guild;
use App\Models\GuildMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class GuildControllerPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_page_is_clamped_to_100(): void
    {
        Bus::fake();

        $guild = Guild::factory()->create([
            'name' => 'echo',
            'realm' => 'tarren-mill',
            'region' => 'eu',
            'roster_synced_at' => now(),
        ]);
        $guild->forceFill(['updated_at' => now()])->save();

        GuildMember::factory()->count(125)->create(['guild_id' => $guild->id]);

        $response = $this->getJson('/api/v1/guilds/eu/tarren-mill/echo?per_page=500');

        $response->assertOk();
        $this->assertSame(100, $response->json('members.per_page'));
        $this->assertCount(100, $response->json('members.data'));
    }

    public function test_filter_longer_than_64_chars_is_rejected(): void
    {
        $this->getJson('/api/v1/guilds/eu/tarren-mill/echo?filter='.str_repeat('a', 65))
            ->assertUnprocessable();
    }

    public function test_negative_per_page_is_rejected(): void
    {
        $this->getJson('/api/v1/guilds/eu/tarren-mill/echo?per_page=-5')
            ->assertUnprocessable();
    }
}
```

- [ ] **Step 2: Run the test and confirm failure**

```bash
docker compose exec app composer test -- --filter=GuildControllerPaginationTest
```

Expected: clamp/filter/negative-value assertions fail.

- [ ] **Step 3: Add validation + clamp in `GuildController::show`**

In `app/Http/Controllers/GuildController.php`, replace lines 39–40:

```php
        $perPage = (int) $request->query('per_page', '50');
        $filter = trim((string) $request->query('filter', ''));
```

with:

```php
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'filter' => ['nullable', 'string', 'max:64'],
        ]);

        $perPage = (int) $request->query('per_page', '50');
        $filter = trim((string) $request->query('filter', ''));
```

(Validation rejects out-of-range values upfront; the cast remains because `min:1, max:100` already constrains the inbound value.)

- [ ] **Step 4: Run the test and confirm green**

```bash
docker compose exec app composer test -- --filter=GuildControllerPaginationTest
```

Expected: all three tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/GuildController.php \
        tests/Feature/Http/GuildControllerPaginationTest.php
git commit -m "fix(guilds): validate roster per_page (max 100) and filter length (max 64)"
```

---

### Task 3: Add OAuth state minting + redirect-URI allowlist

> **DEFERRED — DO NOT EXECUTE.** Requires a paired frontend change (FE must call the new `/state` endpoint and pass `state` back into the code-exchange request). Shipping this without the FE PR breaks live OAuth. Skip to Task 4.

**Files:**
- Modify: `config/blizzard.php`
- Modify: `.env.example`
- Modify: `app/Http/Requests/BlizzardOAuthRequest.php`
- Create: `app/Http/Requests/BlizzardOAuthStateRequest.php`
- Modify: `app/Http/Controllers/BlizzardController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Blizzard/OAuthControllerTest.php`

- [ ] **Step 1: Add allowlist + TTL to config**

In `config/blizzard.php`, add a new section after the existing `'rate_limit'` block (before the closing `];`):

```php
    /*
    |--------------------------------------------------------------------------
    | OAuth (Battle.net)
    |--------------------------------------------------------------------------
    | `redirect_uris` is a comma-separated allowlist; values not in this list
    | are rejected by `BlizzardOAuthRequest` / `BlizzardOAuthStateRequest`.
    | `state_ttl` controls how long a minted CSRF state is cached (seconds).
    */

    'oauth' => [
        'redirect_uris' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'BLIZZARD_OAUTH_REDIRECT_URIS',
                rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/blizzard-oauth'
            ))
        ))),
        'state_ttl' => (int) env('BLIZZARD_OAUTH_STATE_TTL', 600),
    ],
```

In `.env.example`, append:

```env
BLIZZARD_OAUTH_REDIRECT_URIS=http://localhost:5173/blizzard-oauth,http://localhost:8092/blizzard-oauth
BLIZZARD_OAUTH_STATE_TTL=600
```

- [ ] **Step 2: Write failing OAuth tests**

Create `tests/Feature/Blizzard/OAuthControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Blizzard;

use App\Blizzard\Client\BlizzardAuthClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('blizzard.oauth.redirect_uris', ['http://localhost:5173/blizzard-oauth']);
        config()->set('blizzard.oauth.state_ttl', 600);
    }

    public function test_authenticated_user_can_mint_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth/state', [
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
        ]);

        $response->assertOk()->assertJsonStructure(['state', 'expires_in']);

        $state = $response->json('state');
        $this->assertIsString($state);
        $this->assertTrue(Cache::has("blizzard:oauth-state:{$user->id}:eu:{$state}"));
    }

    public function test_state_mint_rejects_untrusted_redirect_uri(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth/state', [
            'redirectUri' => 'https://evil.example/callback',
        ])->assertUnprocessable();
    }

    public function test_state_mint_requires_auth(): void
    {
        $this->postJson('/api/v1/eu/blizzard-oauth/state', [
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
        ])->assertUnauthorized();
    }

    public function test_code_exchange_requires_state_field(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth', [
            'code' => 'abc',
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
        ])->assertUnprocessable();
    }

    public function test_code_exchange_rejects_unknown_state(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth', [
            'code' => 'abc',
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
            'state' => str_repeat('a', 64),
        ])->assertUnprocessable();
    }

    public function test_code_exchange_rejects_untrusted_redirect_uri(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth', [
            'code' => 'abc',
            'redirectUri' => 'https://evil.example/callback',
            'state' => str_repeat('a', 64),
        ])->assertUnprocessable();
    }

    public function test_code_exchange_consumes_cached_state_once(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $state = str_repeat('a', 64);
        Cache::put(
            "blizzard:oauth-state:{$user->id}:eu:{$state}",
            ['redirectUri' => 'http://localhost:5173/blizzard-oauth'],
            600
        );

        $authClient = \Mockery::mock(BlizzardAuthClient::class);
        $authClient->shouldReceive('getOauthAccessToken')
            ->once()
            ->with('eu', 'abc', 'http://localhost:5173/blizzard-oauth')
            ->andReturn((object) ['access_token' => 'token']);
        $this->app->instance(BlizzardAuthClient::class, $authClient);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/eu/blizzard-oauth', [
            'code' => 'abc',
            'redirectUri' => 'http://localhost:5173/blizzard-oauth',
            'state' => $state,
        ])->assertAccepted();

        $this->assertFalse(
            Cache::has("blizzard:oauth-state:{$user->id}:eu:{$state}"),
            'State must be single-use'
        );
    }
}
```

- [ ] **Step 3: Run the test and confirm failure**

```bash
docker compose exec app composer test -- --filter=OAuthControllerTest
```

Expected: 404s on `/state` route, validation passes that should fail, state cache not consumed.

- [ ] **Step 4: Tighten `BlizzardOAuthRequest`**

Replace `app/Http/Requests/BlizzardOAuthRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlizzardOAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'redirectUri' => [
                'required',
                'url',
                Rule::in((array) config('blizzard.oauth.redirect_uris', [])),
            ],
            'state' => ['required', 'string', 'min:32', 'max:128'],
        ];
    }
}
```

- [ ] **Step 5: Create `BlizzardOAuthStateRequest`**

Create `app/Http/Requests/BlizzardOAuthStateRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlizzardOAuthStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'redirectUri' => [
                'required',
                'url',
                Rule::in((array) config('blizzard.oauth.redirect_uris', [])),
            ],
        ];
    }
}
```

- [ ] **Step 6: Add `state()` action and consume state in `handleCode()`**

Replace `app/Http/Controllers/BlizzardController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Blizzard\Client\BlizzardAuthClient;
use App\Blizzard\Jobs\SyncUserCharacters;
use App\Http\Requests\BlizzardOAuthRequest;
use App\Http\Requests\BlizzardOAuthStateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BlizzardController extends Controller
{
    public function state(BlizzardOAuthStateRequest $request, string $region): JsonResponse
    {
        $state = Str::random(64);
        $ttl = (int) config('blizzard.oauth.state_ttl', 600);
        $user = $request->user();

        Cache::put(
            "blizzard:oauth-state:{$user->id}:{$region}:{$state}",
            ['redirectUri' => $request->validated('redirectUri')],
            $ttl
        );

        return response()->json([
            'state' => $state,
            'expires_in' => $ttl,
        ]);
    }

    public function handleCode(BlizzardOAuthRequest $request, string $region): JsonResponse
    {
        $user = $request->user();
        $state = $request->validated('state');
        $cacheKey = "blizzard:oauth-state:{$user->id}:{$region}:{$state}";
        $statePayload = Cache::pull($cacheKey);

        if (! is_array($statePayload)
            || ($statePayload['redirectUri'] ?? null) !== $request->validated('redirectUri')) {
            return response()->json(['message' => 'Invalid OAuth state.'], 422);
        }

        /** @var BlizzardAuthClient $authClient */
        $authClient = app(BlizzardAuthClient::class);

        $tokenResponse = $authClient->getOauthAccessToken(
            $region,
            $request->validated('code'),
            $request->validated('redirectUri'),
        );

        $user->update(['bnet_region' => $region]);

        SyncUserCharacters::dispatch($user, $region, $tokenResponse->access_token);

        return response()->json(['message' => 'Battle.net sync initiated'], 202)
            ->header('Retry-After', '5');
    }
}
```

- [ ] **Step 7: Add the `/state` route**

In `routes/api.php`, replace the existing Blizzard OAuth block (lines 101–108):

```php
/*
|--------------------------------------------------------------------------
| Blizzard OAuth Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:10,1'])
    ->whereIn('region', config('blizzard.regions', ['eu', 'us', 'kr', 'tw']))
    ->group(function () {
        Route::post('/{region}/blizzard-oauth/state', [BlizzardController::class, 'state'])
            ->name('blizzard.oauth.state');
        Route::post('/{region}/blizzard-oauth', [BlizzardController::class, 'handleCode'])
            ->name('blizzard.oauth');
    });
```

- [ ] **Step 8: Run the test and confirm green**

```bash
docker compose exec app composer test -- --filter=OAuthControllerTest
```

Expected: all seven OAuth tests pass.

- [ ] **Step 9: Commit**

```bash
git add config/blizzard.php .env.example \
        app/Http/Requests/BlizzardOAuthRequest.php \
        app/Http/Requests/BlizzardOAuthStateRequest.php \
        app/Http/Controllers/BlizzardController.php \
        routes/api.php \
        tests/Feature/Blizzard/OAuthControllerTest.php
git commit -m "feat(blizzard-oauth): mint state CSRF token and allowlist redirect URIs"
```

---

### Task 4: Add throttles + region constraints to public lookup routes; throttle auth routes

**Files:**
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/PublicRouteThrottleTest.php`

- [ ] **Step 1: Write failing throttle test**

Create `tests/Feature/Http/PublicRouteThrottleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicRouteThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api');
        Bus::fake();
    }

    public function test_character_achievements_route_is_throttled(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/characters/eu/the-maelstrom/melaniya/achievements');
        }

        $this->getJson('/api/v1/characters/eu/the-maelstrom/melaniya/achievements')
            ->assertStatus(429);
    }

    public function test_guild_show_route_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/v1/guilds/eu/tarren-mill/echo');
        }

        $this->getJson('/api/v1/guilds/eu/tarren-mill/echo')
            ->assertStatus(429);
    }

    public function test_login_route_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_unsupported_region_returns_404(): void
    {
        $this->getJson('/api/v1/characters/zz/the-maelstrom/melaniya')
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run the test and confirm failure**

```bash
docker compose exec app composer test -- --filter=PublicRouteThrottleTest
```

Expected: assertions fail for unthrottled achievements/guild/login routes; the unsupported-region request returns 200/202 (no constraint).

- [ ] **Step 3: Apply throttles + region constraints**

In `routes/api.php`, edit each block as follows.

Auth block (replace lines 44–54):

```php
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/password/forgot', ForgotPasswordController::class)->middleware('throttle:3,1');
    Route::post('/password/reset', [ResetPasswordController::class, 'reset'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
    });
});
```

Character + guild blocks (replace lines 56–84). Note we hoist the region constraint into a `whereIn`:

```php
$regions = config('blizzard.regions', ['eu', 'us', 'kr', 'tw']);

/*
|--------------------------------------------------------------------------
| Character Routes
|--------------------------------------------------------------------------
*/
Route::get('/characters/popular', [CharacterController::class, 'popular'])->name('characters.popular');
Route::get('/characters/suggest', [CharacterController::class, 'suggest'])
    ->middleware('throttle:60,1')
    ->name('characters.suggest');
Route::get('/characters/{region}/{realm}/{character}', [CharacterController::class, 'show'])
    ->whereIn('region', $regions)
    ->middleware('throttle:10,1')
    ->name('characters.show');
Route::get('/characters/{region}/{realm}/{character}/achievements', [CharacterAchievementsController::class, 'index'])
    ->whereIn('region', $regions)
    ->middleware('throttle:30,1')
    ->name('characters.achievements');
Route::patch('/characters/{character}/recruitment', [CharacterController::class, 'toggleRecruitment'])
    ->middleware('auth:sanctum')
    ->name('characters.recruitment');

/*
|--------------------------------------------------------------------------
| Guild Routes
|--------------------------------------------------------------------------
*/
Route::get('/guilds/popular', [GuildController::class, 'popular'])->name('guilds.popular');
Route::get('/guilds/suggest', [GuildController::class, 'suggest'])
    ->middleware('throttle:60,1')
    ->name('guilds.suggest');
Route::get('/guilds/discover', [GuildController::class, 'discover'])
    ->middleware('throttle:30,1')
    ->name('guilds.discover');
Route::get('/guilds/{region}/{realm}/{guild}', [GuildController::class, 'show'])
    ->whereIn('region', $regions)
    ->middleware('throttle:10,1')
    ->name('guilds.show');
```

(Game-data routes stay unthrottled — they are long-cacheable and call no Blizzard endpoints at request time.)

- [ ] **Step 4: Run the test and confirm green**

```bash
docker compose exec app composer test -- --filter=PublicRouteThrottleTest
```

Expected: all four tests pass.

- [ ] **Step 5: Commit**

```bash
git add routes/api.php tests/Feature/Http/PublicRouteThrottleTest.php
git commit -m "fix(routes): add throttles and region constraints to public lookups + auth"
```

---

### Task 5: Make Sanctum token expiration env-driven

**Files:**
- Modify: `config/sanctum.php`
- Modify: `.env.example`

(No new test — this only changes a config default; existing auth tests cover token issuance.)

- [ ] **Step 1: Make `expiration` env-driven**

In `config/sanctum.php`, replace line 53:

```php
    'expiration' => null,
```

with:

```php
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION_MINUTES') !== null
        ? (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES')
        : null,
```

- [ ] **Step 2: Document default in `.env.example`**

Append to `.env.example`:

```env
# Minutes until a Sanctum bearer token expires. Default 10080 (1 week);
# leave unset for tokens that never expire.
SANCTUM_TOKEN_EXPIRATION_MINUTES=10080
```

- [ ] **Step 3: Run the auth suite**

```bash
docker compose exec app composer test -- --filter=Auth
```

Expected: existing auth tests pass.

- [ ] **Step 4: Commit**

```bash
git add config/sanctum.php .env.example
git commit -m "fix(sanctum): make token expiration env-driven (default 1 week)"
```

---

### Task 6: Fix doc drift in CLAUDE.md and README.md

**Files:**
- Modify: `CLAUDE.md`
- Modify: `README.md`

- [ ] **Step 1: Correct `BLIZZARD_CRAWL_RECENT_THRESHOLD` value in CLAUDE.md**

In `CLAUDE.md`, find the line in the "Sync orchestration" section that reads:

```
…fresher than `BLIZZARD_CRAWL_RECENT_THRESHOLD` (default 21600 = 6h)…
```

and the matching prose in the "Teammate Crawl" config block (around line 95–96). Replace `21600 = 6h` with `259200 = 3d` in **both** places. The actual default in `config/blizzard.php:101` is `259200`.

- [ ] **Step 2: Backfill missing endpoints in README.md**

In `README.md`, find the API endpoint listing section and add (in the appropriate groupings):

```markdown
GET  /api/v1/health
GET  /api/v1/characters/suggest
GET  /api/v1/characters/{region}/{realm}/{character}/achievements
GET  /api/v1/guilds/suggest
GET  /api/v1/guilds/discover
GET  /api/v1/game-data/raid-instances
GET  /api/v1/game-data/mythic-keystone-dungeons
GET  /api/v1/game-data/talent-trees/{treeId}/{specId}
GET  /api/v1/game-data/realms
POST /api/v1/{region}/blizzard-oauth/state
POST /api/v1/{region}/blizzard-oauth
```

If `README.md` claims the bundled `postman.json` is "ready-to-use with all endpoints," either narrow that claim to "core auth and lookup flows" or update the postman collection in a follow-up. Do not update `postman.json` in this PR.

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md README.md
git commit -m "docs: fix BLIZZARD_CRAWL_RECENT_THRESHOLD value and backfill API endpoints"
```

---

### Task 7: Full verification

**Files:** none.

- [ ] **Step 1: Style check**

```bash
docker compose exec app ./vendor/bin/pint --test
```

Expected: no diffs.

- [ ] **Step 2: Run focused review-fix suites**

```bash
docker compose exec app composer test -- --filter='PasswordResetEndpointTest|GuildControllerPaginationTest|OAuthControllerTest|PublicRouteThrottleTest'
```

Expected: all pass.

- [ ] **Step 3: Run full backend suite**

```bash
docker compose exec app composer test
```

Expected: green.

---

## Self-Review Checklist

- [ ] `ForgotPasswordController` returns the same body for known and unknown emails; reset emails point at `FRONTEND_URL`.
- [ ] `GuildController::show` rejects `per_page > 100` and `filter > 64 chars` with 422.
- [ ] `BlizzardOAuthRequest` requires `state` (32–128 chars) and an allowlisted `redirectUri`.
- [ ] `POST /api/v1/{region}/blizzard-oauth/state` exists, requires sanctum, and caches a single-use payload keyed by `user:region:state`.
- [ ] `handleCode` returns 422 when the cached state is missing or its `redirectUri` doesn't match.
- [ ] `/characters/.../achievements` and `/guilds/...` lookup routes are throttled and constrained to supported regions.
- [ ] Auth routes (`/register`, `/login`, `/password/forgot`, `/password/reset`) are throttled.
- [ ] `config/sanctum.php` reads `SANCTUM_TOKEN_EXPIRATION_MINUTES` from env; `.env.example` documents the default.
- [ ] `CLAUDE.md` matches `config/blizzard.php` for `BLIZZARD_CRAWL_RECENT_THRESHOLD` (259200).
- [ ] `README.md` lists every public route in `routes/api.php`.
