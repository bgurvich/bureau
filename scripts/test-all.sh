#!/usr/bin/env bash
#
# Run every check: dependency audits, Pint, PHPStan, Pest, Playwright.
# Usage: ./scripts/test-all.sh [--fast] [--e2e-only]
#   --fast      Skip audits + PHPStan + Pint --test (saves ~30s)
#   --e2e-only  Only run Playwright
#
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'; BOLD='\033[1m'; NC='\033[0m'

FAST=false
E2E_ONLY=false
for arg in "$@"; do
    [[ "$arg" == "--fast" ]] && FAST=true
    [[ "$arg" == "--e2e-only" ]] && E2E_ONLY=true
done

FAILED=(); PASSED=(); SKIPPED=()

run_step() {
    local label="$1"; shift
    echo -e "\n${BOLD}── ${label} ──${NC}"
    if "$@"; then
        PASSED+=("$label"); echo -e "${GREEN}✓ ${label}${NC}"
    else
        FAILED+=("$label"); echo -e "${RED}✗ ${label}${NC}"
    fi
}

skip_step() { SKIPPED+=("$1"); echo -e "\n${YELLOW}○ $1 (skipped)${NC}"; }

cd "$(cd "$(dirname "$0")/.." && pwd)"

if [[ "$E2E_ONLY" == false ]]; then
    if [[ "$FAST" == false ]]; then
        run_step "Composer audit" composer audit
        run_step "NPM audit"      npm audit --omit=dev
        run_step "Pint"           vendor/bin/pint --test
        run_step "PHPStan"        vendor/bin/phpstan analyse --memory-limit=512M --no-progress
        run_step "TypeScript"     npx tsc --noEmit
    else
        skip_step "Audits + Pint + PHPStan + TypeScript"
    fi

    run_step "Pest" php artisan test --stop-on-failure
fi

run_step "Playwright" npx playwright test

echo -e "\n${BOLD}══ Summary ══${NC}"
for p in "${PASSED[@]}";  do echo -e "  ${GREEN}✓${NC} $p"; done
for s in "${SKIPPED[@]}"; do echo -e "  ${YELLOW}○${NC} $s"; done
for f in "${FAILED[@]}";  do echo -e "  ${RED}✗${NC} $f"; done

if [[ ${#FAILED[@]} -gt 0 ]]; then
    echo -e "\n${RED}${#FAILED[@]} failed.${NC}"; exit 1
else
    echo -e "\n${GREEN}All checks passed.${NC}"
fi
