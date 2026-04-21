#!/usr/bin/env bash
#
# Run OWASP ZAP security scans against the Bureau application.
# Requires: Docker, running app on localhost:8000
#
# Usage:
#   ./scripts/run-zap.sh baseline     # Passive scan (safe, fast)
#   ./scripts/run-zap.sh full         # Active scan (attacks target)
#   ./scripts/run-zap.sh auth         # Authenticated active scan
#   ./scripts/run-zap.sh baseline --ci  # Exit with failure code on High findings
#
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ZAP_DIR="$PROJECT_DIR/zap"
MODE="${1:-baseline}"
CI_MODE=false
[[ "${2:-}" == "--ci" ]] && CI_MODE=true
TARGET="${ZAP_TARGET:-http://localhost:8000}"

# ── Preflight checks ─────────────────────────────────────────────────────────
if ! command -v docker &>/dev/null; then
    echo -e "${RED}✗ Docker not found. Run ./scripts/setup-zap.sh first.${NC}"
    exit 1
fi

if ! docker image inspect ghcr.io/zaproxy/zaproxy:stable &>/dev/null; then
    echo -e "${YELLOW}ZAP image not found locally. Pulling...${NC}"
    docker pull ghcr.io/zaproxy/zaproxy:stable
fi

# Check target is reachable
if ! curl -sf --max-time 5 "$TARGET" >/dev/null 2>&1; then
    echo -e "${RED}✗ Target $TARGET is not reachable.${NC}"
    echo "  Start your app first: php artisan serve --host=0.0.0.0 --port=8000"
    exit 1
fi

mkdir -p "$ZAP_DIR"

TIMESTAMP=$(date +%Y%m%d-%H%M%S)

# ── Common Docker options ─────────────────────────────────────────────────────
DOCKER_OPTS=(
    --rm
    -v "$ZAP_DIR:/zap/wrk/:rw"
    --network=host
    --user "$(id -u):$(id -g)"
    ghcr.io/zaproxy/zaproxy:stable
)

echo -e "${BOLD}── OWASP ZAP: ${MODE} scan ──${NC}"
echo -e "Target: ${TARGET}"
echo -e "Output: ${ZAP_DIR}/\n"

case "$MODE" in
    baseline)
        # Passive-only scan: spider + passive rules, no active attacks
        REPORT_HTML="zap-baseline-${TIMESTAMP}.html"
        REPORT_JSON="zap-baseline-${TIMESTAMP}.json"

        EXTRA_FLAGS="-I"
        if [[ "$CI_MODE" == true ]]; then
            EXTRA_FLAGS=""  # Allow failure exit codes in CI
        fi

        docker run "${DOCKER_OPTS[@]}" \
            zap-baseline.py \
            -t "$TARGET" \
            -r "$REPORT_HTML" \
            -J "$REPORT_JSON" \
            -c "/zap/wrk/rules.tsv" \
            $EXTRA_FLAGS \
        || EXIT_CODE=$?

        ;;
    full)
        # Full active scan: spider + passive + active attack rules
        REPORT_HTML="zap-full-${TIMESTAMP}.html"
        REPORT_JSON="zap-full-${TIMESTAMP}.json"

        EXTRA_FLAGS="-I"
        if [[ "$CI_MODE" == true ]]; then
            EXTRA_FLAGS=""
        fi

        docker run "${DOCKER_OPTS[@]}" \
            zap-full-scan.py \
            -t "$TARGET" \
            -r "$REPORT_HTML" \
            -J "$REPORT_JSON" \
            -c "/zap/wrk/rules.tsv" \
            -m 10 \
            $EXTRA_FLAGS \
        || EXIT_CODE=$?

        ;;
    auth)
        # Authenticated baseline scan: mint a signed-in session via
        # `artisan zap:session`, then run zap-baseline with the session cookie
        # injected on every outbound request. Drops the brittle browser-based
        # automation flow that needed a seeded user with a known password.
        REPORT_HTML="zap-auth-${TIMESTAMP}.html"
        REPORT_JSON="zap-auth-${TIMESTAMP}.json"

        echo -e "${YELLOW}Minting session cookie via artisan zap:session...${NC}"
        COOKIE_KV="$(cd "$PROJECT_DIR" && php artisan zap:session --no-interaction 2>/dev/null | tail -n 1)"
        if [[ -z "$COOKIE_KV" || "$COOKIE_KV" != *"="* ]]; then
            echo -e "${RED}✗ Could not mint a session cookie (empty output from artisan zap:session).${NC}"
            exit 1
        fi
        echo -e "${GREEN}✓${NC} Session cookie prepared"

        EXTRA_FLAGS="-I"
        if [[ "$CI_MODE" == true ]]; then
            EXTRA_FLAGS=""
        fi

        docker run "${DOCKER_OPTS[@]}" \
            zap-baseline.py \
            -t "$TARGET" \
            -r "$REPORT_HTML" \
            -J "$REPORT_JSON" \
            -c "/zap/wrk/rules.tsv" \
            $EXTRA_FLAGS \
            -z "-config replacer.full_list(0).description=authcookie \
                -config replacer.full_list(0).enabled=true \
                -config replacer.full_list(0).matchtype=REQ_HEADER \
                -config replacer.full_list(0).matchstr=Cookie \
                -config replacer.full_list(0).regex=false \
                -config replacer.full_list(0).replacement=${COOKIE_KV}" \
        || EXIT_CODE=$?

        ;;
    *)
        echo -e "${RED}Unknown mode: $MODE${NC}"
        echo "Usage: $0 [baseline|full|auth] [--ci]"
        exit 1
        ;;
esac

EXIT_CODE="${EXIT_CODE:-0}"

# ── Results ───────────────────────────────────────────────────────────────────
echo ""
if [[ -f "$ZAP_DIR/$REPORT_HTML" ]]; then
    echo -e "${GREEN}✓${NC} HTML report: $ZAP_DIR/$REPORT_HTML"
fi
if [[ -n "${REPORT_JSON:-}" && -f "$ZAP_DIR/$REPORT_JSON" ]]; then
    echo -e "${GREEN}✓${NC} JSON report: $ZAP_DIR/$REPORT_JSON"

    # Print summary from JSON
    if command -v python3 &>/dev/null; then
        python3 -c "
import json, sys
with open('$ZAP_DIR/$REPORT_JSON') as f:
    data = json.load(f)
alerts = data.get('site', [{}])[0].get('alerts', []) if data.get('site') else []
counts = {'High': 0, 'Medium': 0, 'Low': 0, 'Informational': 0}
for a in alerts:
    risk = a.get('riskdesc', '').split(' ')[0]
    if risk in counts:
        counts[risk] += 1
print()
print('  Findings:')
for risk, count in counts.items():
    marker = '✗' if risk in ('High', 'Medium') and count > 0 else '✓'
    print(f'    {marker} {risk}: {count}')
" 2>/dev/null || true
    fi
fi

echo ""
if [[ "$EXIT_CODE" -gt 0 && "$CI_MODE" == true ]]; then
    echo -e "${RED}Scan found issues — see report for details.${NC}"
    exit "$EXIT_CODE"
elif [[ "$EXIT_CODE" -gt 0 ]]; then
    echo -e "${YELLOW}Scan completed with findings (informational mode — not failing).${NC}"
else
    echo -e "${GREEN}Scan completed successfully.${NC}"
fi
