#!/usr/bin/env bash
#
# Install and configure OWASP ZAP for security testing.
# Usage: ./scripts/setup-zap.sh
#
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

echo -e "${BOLD}── OWASP ZAP Setup ──${NC}\n"

# ── 1. Check Docker ──────────────────────────────────────────────────────────
if command -v docker &>/dev/null; then
    echo -e "${GREEN}✓${NC} Docker found: $(docker --version)"
else
    echo -e "${RED}✗ Docker not found.${NC}"
    echo "  Install Docker: https://docs.docker.com/get-docker/"
    echo "  Or on WSL: sudo apt install docker.io && sudo usermod -aG docker \$USER"
    exit 1
fi

# ── 2. Pull ZAP image ────────────────────────────────────────────────────────
echo -e "\n${BOLD}Pulling ZAP stable image...${NC}"
docker pull ghcr.io/zaproxy/zaproxy:stable
echo -e "${GREEN}✓${NC} ZAP image pulled"

# ── 3. Create ZAP config directory ────────────────────────────────────────────
ZAP_DIR="$(cd "$(dirname "$0")/.." && pwd)/zap"
mkdir -p "$ZAP_DIR"

# ── 4. Write automation config ────────────────────────────────────────────────
cat > "$ZAP_DIR/automation.yaml" << 'YAML'
# OWASP ZAP Automation Framework config for Bureau Dashboard.
# Run with: ./scripts/run-zap.sh [baseline|full|auth]
#
# Target: http://localhost:8000 (local dev or staging)
# Auth: boris@gurvich.me / password (dev seed users)
#
# NOTE: Disable mandatory 2FA in the test environment before running.

env:
  contexts:
    - name: "Bureau"
      urls:
        - "http://localhost:8000"
      includePaths:
        - "http://localhost:8000.*"
      excludePaths:
        - "http://localhost:8000/logout"
        - "http://localhost:8000/_debugbar/.*"
        - "http://localhost:8000/up"
        - "http://localhost:8000/health"
        - "http://localhost:8000/livewire/livewire.*"
      authentication:
        method: "browser"
        parameters:
          loginPageUrl: "http://localhost:8000/login"
          browserId: "firefox-headless"
        verification:
          method: "response"
          loggedInRegex: "\\Qdashboard\\E"
          loggedOutRegex: "\\Qlogin\\E"
      sessionManagement:
        method: "cookie"
      users:
        - name: "admin"
          credentials:
            username: "boris@gurvich.me"
            password: "change-me"

jobs:
  - type: spider
    parameters:
      context: "Bureau"
      user: "admin"
      maxDuration: 5
      maxDepth: 5
  - type: spiderAjax
    parameters:
      context: "Bureau"
      user: "admin"
      maxDuration: 5
  - type: passiveScan-wait
    parameters:
      maxDuration: 5
  - type: activeScan
    parameters:
      context: "Bureau"
      user: "admin"
      maxRuleDurationInMins: 5
      maxScanDurationInMins: 30
  - type: report
    parameters:
      template: "traditional-html"
      reportDir: "/zap/wrk"
      reportFile: "zap-auth-report"
    risks:
      - high
      - medium
      - low
      - info
YAML

# ── 5. Write false-positive rules ────────────────────────────────────────────
cat > "$ZAP_DIR/rules.tsv" << 'TSV'
# ZAP rule overrides for Bureau — suppress known false positives
# Format: ruleId<TAB>action<TAB>reason
10038	IGNORE	(CSP unsafe-eval required for Alpine.js inline expressions)
10098	IGNORE	(Cross-Domain Misconfiguration — internal single-origin app)
TSV

# ── 6. Add zap/ to .gitignore if not already ─────────────────────────────────
GITIGNORE="$(cd "$(dirname "$0")/.." && pwd)/.gitignore"
if ! grep -q '^/zap/' "$GITIGNORE" 2>/dev/null; then
    echo -e "\n# OWASP ZAP reports and config\n/zap/*.html\n/zap/*.json" >> "$GITIGNORE"
    echo -e "${GREEN}✓${NC} Added ZAP report patterns to .gitignore"
fi

# ── 7. Verify ─────────────────────────────────────────────────────────────────
echo -e "\n${BOLD}── Setup Complete ──${NC}"
echo -e "${GREEN}✓${NC} ZAP Docker image: ghcr.io/zaproxy/zaproxy:stable"
echo -e "${GREEN}✓${NC} Automation config: zap/automation.yaml"
echo -e "${GREEN}✓${NC} Rule overrides:    zap/rules.tsv"
echo ""
echo -e "Next steps:"
echo -e "  1. Start your app:  ${BOLD}php artisan serve --host=0.0.0.0 --port=8000${NC}"
echo -e "  2. Run a scan:      ${BOLD}./scripts/run-zap.sh baseline${NC}"
echo -e "  3. View report:     ${BOLD}open zap/zap-baseline-report.html${NC}"
echo ""
echo -e "Scan modes:"
echo -e "  ${BOLD}baseline${NC}  — passive only, safe, fast (~2 min)"
echo -e "  ${BOLD}full${NC}      — active attacks, thorough (~10-30 min)"
echo -e "  ${BOLD}auth${NC}      — authenticated crawl + active scan (~15-45 min)"
