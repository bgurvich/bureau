#!/usr/bin/env bash
#
# Run the PHP test suite (Pest, against in-memory sqlite per phpunit.xml).
# Usage: ./scripts/run-tests.sh [pest-args...]
#   ./scripts/run-tests.sh                          # full suite
#   ./scripts/run-tests.sh --stop-on-failure
#   ./scripts/run-tests.sh tests/Feature/UserMenuTest.php
#
set -euo pipefail

cd "$(cd "$(dirname "$0")/.." && pwd)"

echo "── Running tests ──"
php artisan config:clear --ansi >/dev/null
./vendor/bin/pest "$@" 2>&1 | tee /tmp/bureau-test-output.txt
