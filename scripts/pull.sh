#!/usr/bin/env bash
#
# Pull latest code and refresh dependencies + build.
# Usage: ./scripts/pull.sh [--full]
#   --full  Also run the full check suite (PHPStan + Pest + Playwright) after pull
#
set -euo pipefail

BOLD='\033[1m'; NC='\033[0m'

cd "$(cd "$(dirname "$0")/.." && pwd)"
FULL=false
[[ "${1:-}" == "--full" ]] && FULL=true

echo -e "${BOLD}── Pull ──${NC}"
git pull

echo -e "\n${BOLD}── Composer ──${NC}"
composer install --quiet

echo -e "\n${BOLD}── NPM ──${NC}"
npm ci --silent

echo -e "\n${BOLD}── Build ──${NC}"
npx vite build

echo -e "\n${BOLD}── Migrate ──${NC}"
php artisan migrate --force

php artisan config:clear
php artisan view:clear
php artisan route:clear

if [[ "$FULL" == true ]]; then
    ./scripts/test-all.sh
fi
