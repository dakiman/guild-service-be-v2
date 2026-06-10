#!/bin/bash
set -e

echo "==> Pulling latest code..."
git pull origin main

echo "==> Building new image..."
docker compose build app

echo "==> Running migrations..."
docker compose run --rm app php artisan migrate --force

echo "==> Terminating Horizon gracefully..."
docker compose exec horizon php artisan horizon:terminate 2>/dev/null || true
sleep 5

echo "==> Recreating containers..."
docker compose up -d --remove-orphans

echo "==> Caching configuration..."
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan event:cache
docker compose exec app php artisan view:cache

echo "==> Verifying..."
docker compose exec app php artisan horizon:status
curl -sf http://localhost/api/v1/health || echo "WARNING: Health check failed"

echo "==> Deployment complete!"
