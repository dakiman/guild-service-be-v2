#!/usr/bin/env bash
# Run a PHP-toolchain command inside the guild-service-test image with the
# working tree mounted — the host has no PHP. Runs as the host user so
# pint/phpunit never leave root-owned files in the tree.
#
# Usage (from backend/):
#   ./dphp.sh ./vendor/bin/phpunit tests/Feature/Http/TopKeysControllerTest.php
#   ./dphp.sh ./vendor/bin/pint app/Models/GameDataSeason.php
#   ./dphp.sh ./vendor/bin/phpunit --exclude-group=integration   # full suite
#
# bootstrap/cache/config.php is removed first: a stale cached config from a
# previous artisan run otherwise shadows phpunit.xml's env (SQLite etc.).
set -euo pipefail
cd "$(dirname "$0")"
exec sg docker -c "docker run --rm -u $(id -u):$(id -g) -v \"$PWD\":/var/www/html -w /var/www/html --entrypoint sh guild-service-test:latest -c 'rm -f bootstrap/cache/config.php; $*'"
