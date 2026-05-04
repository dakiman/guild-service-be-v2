# WoW Guild Service API

A World of Warcraft guild and character lookup API. Fetches and caches data from the Blizzard API, provides search, character profiles, guild rosters, and mythic+ dungeon run tracking.

## Stack

- **Laravel 13** (PHP 8.4)
- **PostgreSQL 16** with JSONB columns
- **Redis 7** for cache + queues
- **Laravel Horizon** for queue management
- **Laravel Sanctum** for API authentication
- **Docker Compose** (6 containers)

## Base URL

`http://localhost:8091/api/v1`

## Quick start

```bash
cp .env.example .env
cp docker-compose.override.yml.example docker-compose.override.yml
docker run --rm -v $(pwd):/app -w /app composer:2 composer install --ignore-platform-req=ext-pcntl
docker run --rm -v $(pwd):/app -w /app composer:2 php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app chmod -R 777 storage bootstrap/cache    # dev only
```

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
| GET | `/characters/suggest?q=` | No | Typeahead name search |
| GET | `/characters/{region}/{realm}/{character}` | No | Character lookup (returns 202 if syncing) |
| GET | `/characters/{region}/{realm}/{character}/achievements` | No | Cursor-paginated achievement list |
| PATCH | `/characters/{character}/recruitment` | Yes | Toggle "looking for guild" status |

### Guilds

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/guilds/popular` | No | Recently searched + most popular |
| GET | `/guilds/suggest?q=` | No | Typeahead name search |
| GET | `/guilds/discover` | No | Featured guilds for the discover view |
| GET | `/guilds/{region}/{realm}/{guild}` | No | Guild lookup with paginated roster |

### Game Data (long-cacheable)

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/game-data/raid-instances?expansion=current\|all` | No | Raid instances + encounters |
| GET | `/game-data/mythic-keystone-dungeons?season=current` | No | Season's M+ dungeons + affixes |
| GET | `/game-data/talent-trees/{treeId}/{specId}` | No | Talent tree definitions |
| GET | `/game-data/realms` | No | Realm slugs and names |

### Blizzard OAuth

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/{region}/blizzard-oauth` | Yes | Link Battle.net account |

### Response Behavior

- **200**: Data found and fresh
- **202 + Retry-After: 5**: Entity not yet in DB, sync dispatched. Poll after 5 seconds.
- **X-Data-Staleness: stale** header: Cached data returned, background refresh dispatched.

## Postman Collection

Import `postman.json` into Postman for a starter collection covering the core auth and character/guild lookup flows, with variables and auto-token management. The full set of endpoints listed above is not yet in the collection.

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
| `HORIZON_ADMIN_EMAILS` | (empty) | Comma-separated emails allowed to access Horizon dashboard |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | `10080` | Minutes until bearer tokens expire (1 week). Unset = never expire. |

## Architecture

See `CLAUDE.md` for module layout, request flow, and slice semantics.

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
