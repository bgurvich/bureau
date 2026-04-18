#!/usr/bin/env bash
# =============================================================================
# Bureau — OS package installation (Ubuntu / Debian)
# Usage: sudo bash scripts/deploy/install-packages.sh
# =============================================================================
# Installs everything Bureau needs to run end-to-end:
#   - Laravel stack: nginx, mariadb, redis, php 8.3 + extensions, composer
#   - Node 22 via NVM (for Vite)
#   - OCR pipeline: tesseract-ocr + language pack
#   - Media toolchain: imagemagick (PNG generation from SVG, thumbnailing),
#     poppler-utils (pdftotext fallback for PDFs — future OCR tier)
# Safe to re-run: apt will skip already-installed packages.
# =============================================================================
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo bash $0"
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive
PHP_VER=${PHP_VER:-8.3}
NODE_VER=${NODE_VER:-22}
NVM_DIR=${NVM_DIR:-/opt/nvm}

echo "── Bureau: installing OS packages ──────────────────────"
echo "  PHP $PHP_VER · Node $NODE_VER"
echo ""

dpkg --configure -a 2>/dev/null || true
apt-get update

# Base utilities
apt-get install -y --no-install-recommends \
    git unzip curl wget ca-certificates gnupg2 lsb-release \
    software-properties-common apt-transport-https

# PHP 8.3 from Ondrej PPA when not already on a recent enough base
if ! dpkg -l | grep -q "^ii  php${PHP_VER}-cli"; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update
fi

# Core Laravel stack
apt-get install -y --no-install-recommends \
    nginx mariadb-server redis-server composer \
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" \
    "php${PHP_VER}-redis" "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" \
    "php${PHP_VER}-curl" "php${PHP_VER}-zip" "php${PHP_VER}-bcmath" \
    "php${PHP_VER}-intl" "php${PHP_VER}-gd" "php${PHP_VER}-opcache"

# OCR + media
apt-get install -y --no-install-recommends \
    tesseract-ocr tesseract-ocr-eng \
    imagemagick \
    poppler-utils

# ImageMagick policy defaults block PDF rasterization for security. Enable it
# if we want pdftoppm → tesseract later; harmless otherwise.
POLICY=/etc/ImageMagick-6/policy.xml
if [ -f "$POLICY" ]; then
    sed -i 's|<policy domain="coder" rights="none" pattern="PDF"|<policy domain="coder" rights="read\|write" pattern="PDF"|' "$POLICY" || true
fi

# Node via NVM — system-wide at /opt/nvm, symlinked into /usr/local/bin
if [ ! -d "$NVM_DIR" ]; then
    git clone --quiet --depth=1 https://github.com/nvm-sh/nvm.git "$NVM_DIR"
fi
# shellcheck disable=SC1091
source "$NVM_DIR/nvm.sh"
nvm install "$NODE_VER" >/dev/null
nvm alias default "$NODE_VER" >/dev/null
ln -sf "$(nvm which "$NODE_VER")"                        /usr/local/bin/node
ln -sf "$(dirname "$(nvm which "$NODE_VER")")/npm"       /usr/local/bin/npm
ln -sf "$(dirname "$(nvm which "$NODE_VER")")/npx"       /usr/local/bin/npx

echo ""
echo "── Installed ───────────────────────────────────────────"
printf "  PHP:       %s\n" "$(php -v | head -1)"
printf "  Composer:  %s\n" "$(composer --version | head -1)"
printf "  Node:      %s\n" "$(node -v)"
printf "  MariaDB:   %s\n" "$(mariadb --version)"
printf "  Redis:     %s\n" "$(redis-server --version | awk '{print $3}')"
printf "  Tesseract: %s\n" "$(tesseract --version 2>&1 | head -1)"
printf "  ImageMgk:  %s\n" "$(convert --version | head -1)"
printf "  Poppler:   pdftotext %s\n" "$(pdftotext -v 2>&1 | head -1 | awk '{print $3}')"
echo ""
echo "Done."
