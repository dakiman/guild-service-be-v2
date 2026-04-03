# WoW Guild Service API

A World of Warcraft guild and character lookup API. Fetches and caches data from the Blizzard API, provides search, character profiles, guild rosters, and mythic+ dungeon run tracking.

## Stack

- **Laravel 13** (PHP 8.4)
- **PostgreSQL 16** with JSONB columns
- **Redis 7** for cache + queues
- **Laravel Horizon** for queue management
- **Laravel Sanctum** for API authentication
- **Docker Compose** (6 containers)

## API Base URL

```
http://localhost:8091/api/v1
```

## Quick Start

```bash
# 1. Clone and enter the project
cd guild-service-be-v2

# 2. Copy environment file
cp .env.example .env

# 3. Copy dev docker override
cp docker-compose.override.yml.example docker-compose.override.yml

# 4. Install PHP dependencies (via Docker)
docker run --rm -v $(pwd):/app -w /app composer:2 composer install --ignore-platform-req=ext-pcntl

# 5. Generate app key
docker run --rm -v $(pwd):/app -w /app composer:2 php artisan key:generate

# 6. Start all services
docker compose up -d

# 7. Run database migrations
docker compose exec app php artisan migrate

# 8. (Optional) Publish Sanctum migration if not present
docker compose exec app php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
docker compose exec app php artisan migrate

# 9. Fix storage permissions (dev only)
docker compose exec app chmod -R 777 storage bootstrap/cache
```

The API is now running at **http://localhost:8091/api/v1**.

## Docker Services

| Service | Port (dev) | Description |
|---------|-----------|-------------|
| **nginx** | `8091` | Reverse proxy, serves the API |
| **app** | (internal) | PHP 8.4-FPM, runs Laravel |
| **horizon** | (internal) | Queue worker supervisor + dashboard |
| **scheduler** | (internal) | Runs scheduled tasks (cron replacement) |
| **postgres** | `5433` | PostgreSQL 16 database |
| **redis** | `6380` | Cache, queues, sessions |

### Useful Commands

```bash
# View all container status
docker compose ps

# View logs (all or specific service)
docker compose logs -f
docker compose logs -f app

# Run artisan commands
docker compose exec app php artisan <command>

# Access Horizon dashboard (dev only)
# http://localhost:8091/horizon

# Restart Horizon after code changes
docker compose restart horizon

# Stop everything
docker compose down

# Stop and destroy volumes (database data!)
docker compose down -v
```

## API Endpoints

### Health

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/health` | No | Database + Redis connectivity check |

### Authentication

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/auth/register` | No | Register a new user |
| POST | `/auth/login` | No | Login, returns bearer token |
| POST | `/auth/logout` | Yes | Invalidate current token |
| GET | `/auth/user` | Yes | Get authenticated user + characters |
| POST | `/auth/password/forgot` | No | Request password reset email |
| POST | `/auth/password/reset` | No | Reset password with token |

### Characters

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/characters/popular` | No | Recently searched + most popular |
| GET | `/characters/{region}/{realm}/{name}` | No | Character lookup (returns 202 if syncing) |
| PATCH | `/characters/{id}/recruitment` | Yes | Toggle "looking for guild" status |

### Guilds

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/guilds/popular` | No | Recently searched + most popular |
| GET | `/guilds/{region}/{realm}/{name}` | No | Guild lookup with paginated roster |

### Blizzard OAuth

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/{region}/blizzard-oauth` | Yes | Link Battle.net account |

### Response Behavior

- **200**: Data found and fresh
- **202 + Retry-After: 5**: Entity not yet in DB, sync dispatched. Poll after 5 seconds.
- **X-Data-Staleness: stale** header: Cached data returned, background refresh dispatched.

## Postman Collection

Import `postman.json` into Postman for a ready-to-use collection with all endpoints, variables, and auto-token management.

## Blizzard API Setup

1. Create an application at [Blizzard Developer Portal](https://develop.battle.net/)
2. Set the redirect URI to your frontend's OAuth callback URL
3. Add your credentials to `.env`:

```env
BLIZZARD_CLIENT_ID=your_client_id
BLIZZARD_CLIENT_SECRET=your_client_secret
```

## Configuration

Key `.env` variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_PORT` | `8091` | Port nginx listens on |
| `FRONTEND_URL` | `http://localhost:5173` | Frontend URL for CORS and password reset links |
| `BLIZZARD_STALE_CHARACTER_PROFILE` | `900` | Seconds before character data is re-fetched |
| `BLIZZARD_STALE_GUILD_ROSTER` | `7200` | Seconds before guild roster is re-synced |
| `BLIZZARD_MIN_LEVEL_FOR_LOOKUP` | `70` | Min level to sync characters from guild rosters |
| `BLIZZARD_MYTHIC_SEASON_OVERRIDE` | (empty) | Override auto-detected M+ season ID |

## Architecture

```
app/
  Blizzard/           # Blizzard API integration module
    Client/           # HTTP clients (auth, profile, game data, user)
    Contracts/        # Interfaces (TokenManager)
    DTO/              # Readonly data transfer objects
    Exceptions/       # API-specific exceptions
    Jobs/             # Queue jobs (sync character, guild, roster, proactive)
    Mappers/          # API response -> DTO transformers
    Middleware/        # Job middleware (rate limiter, health check)
  Enums/              # Region, Faction, SyncDepth, ItemQuality
  Http/
    Controllers/      # Thin controllers (Auth, Character, Guild, Blizzard)
    Middleware/        # ForceJsonResponse
    Requests/         # Form request validation
    Resources/        # API response transformers
  Models/             # Eloquent models (User, Character, Guild, etc.)
  Policies/           # Authorization (CharacterPolicy)
  Services/           # Business logic (CharacterService, GuildService)
config/
  blizzard.php        # Blizzard API config (credentials, timeouts, staleness)
  horizon.php         # Queue supervisor config
docker/               # Docker infrastructure files
```

## Queue Architecture

Jobs are processed by Horizon with priority queues:

| Queue | Priority | Jobs |
|-------|----------|------|
| `blizzard-auth` | Highest | Token refresh, OAuth |
| `blizzard-user-sync` | High | User-initiated character/guild lookups |
| `blizzard-roster-sync` | Medium | Guild roster fan-out (batched) |
| `blizzard-background` | Low | Proactive sync for popular entities |
| `default` | Normal | Everything else |

### Scheduled Tasks

| Task | Schedule |
|------|----------|
| Blizzard token refresh | Every 12 hours |
| Horizon metrics snapshot | Every 5 minutes |
| Proactive sync tier 1 (popular chars) | Every 30 minutes |
| Proactive sync tier 2 (active chars) | Every 2 hours |
| Proactive guild roster sync | Daily at 04:00 |
| Prune old batches | Daily |
| Prune failed jobs | Daily |

## Production Deployment

See `deploy.sh` for the deployment script. Key steps:

1. Build the Docker image (uses multi-stage Dockerfile with PHP 8.4-FPM Alpine)
2. Run migrations
3. Gracefully terminate Horizon
4. Recreate containers
5. Cache config/routes/events
6. Verify health

For SSL, add the Caddy container (config in `docker/caddy/Caddyfile`) and set your domain.
