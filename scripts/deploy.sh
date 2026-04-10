#!/usr/bin/env bash
# ============================================================================
# AutoBlogger Manual Deploy Script
#
# Packages the plugin into a clean zip and deploys it to WordPress via the
# deploy-receiver REST endpoint. Use this for hotfixes or local deploys when
# you don't want to go through the full GitHub Actions pipeline.
#
# Usage:
#   ./scripts/deploy.sh
#
# Environment variables (set in .env or export before running):
#   DEPLOY_URL  — Full URL to the deploy endpoint
#   DEPLOY_KEY  — The deploy authentication token
#
# If not set, the script will look for a .env file in the project root.
#
# @see .github/workflows/deploy.yml — Automated pipeline (same deploy step).
# @see .github/mu-plugins/autoblogger-deploy-receiver.php — Server endpoint.
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Colors for output.
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

info()  { echo -e "${GREEN}[deploy]${NC} $*"; }
warn()  { echo -e "${YELLOW}[deploy]${NC} $*"; }
error() { echo -e "${RED}[deploy]${NC} $*" >&2; }

# ── Load environment ────────────────────────────────────────────────────────

if [[ -f "$PROJECT_ROOT/.env" ]]; then
    # shellcheck source=/dev/null
    source "$PROJECT_ROOT/.env"
fi

if [[ -z "${DEPLOY_URL:-}" ]]; then
    error "DEPLOY_URL not set. Export it or add to .env"
    exit 1
fi

if [[ -z "${DEPLOY_KEY:-}" ]]; then
    error "DEPLOY_KEY not set. Export it or add to .env"
    exit 1
fi

# ── Read version ────────────────────────────────────────────────────────────

VERSION=$(grep -oP 'Version:\s*\K[0-9]+\.[0-9]+\.[0-9]+' "$PROJECT_ROOT/autoblogger.php" || echo "0.0.0")
info "AutoBlogger v${VERSION}"

# ── PHP lint check ──────────────────────────────────────────────────────────

info "Running PHP syntax check..."
LINT_ERRORS=0
while IFS= read -r -d '' file; do
    if ! php -l "$file" > /dev/null 2>&1; then
        error "Syntax error in: $file"
        php -l "$file"
        LINT_ERRORS=$((LINT_ERRORS + 1))
    fi
done < <(find "$PROJECT_ROOT" -name '*.php' -not -path '*/vendor/*' -not -path '*/tests/*' -not -path '*/.github/*' -print0)

if [[ $LINT_ERRORS -gt 0 ]]; then
    error "$LINT_ERRORS file(s) have syntax errors. Aborting deploy."
    exit 1
fi
info "Lint passed."

# ── Package ─────────────────────────────────────────────────────────────────

BUILD_DIR=$(mktemp -d)
PLUGIN_DIR="$BUILD_DIR/autoblogger"
ZIP_PATH="$BUILD_DIR/autoblogger-${VERSION}.zip"

info "Packaging plugin..."

mkdir -p "$PLUGIN_DIR"

# Copy production files, excluding dev artifacts.
rsync -a \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.gitignore' \
    --exclude='.distignore' \
    --exclude='.env' \
    --exclude='.env.local' \
    --exclude='tests/' \
    --exclude='scripts/' \
    --exclude='vendor/' \
    --exclude='node_modules/' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='phpunit.xml' \
    --exclude='phpunit.xml.dist' \
    --exclude='.phpcs.xml' \
    --exclude='ARCHITECTURE.md' \
    --exclude='CONVENTIONS.md' \
    --exclude='CHANGELOG.md' \
    --exclude='build/' \
    --exclude='*.log' \
    --exclude='.DS_Store' \
    "$PROJECT_ROOT/" "$PLUGIN_DIR/"

cd "$BUILD_DIR"
zip -rq "$ZIP_PATH" autoblogger/

ZIP_SIZE=$(du -h "$ZIP_PATH" | cut -f1)
info "Built ${ZIP_PATH} (${ZIP_SIZE})"

# ── Pre-deploy health check ────────────────────────────────────────────────

STATUS_URL="${DEPLOY_URL%/deploy}/status"
info "Checking deploy endpoint health..."
STATUS_RESPONSE=$(curl -sS -w "\n%{http_code}" \
    -H "X-Deploy-Key: ${DEPLOY_KEY}" \
    "${STATUS_URL}" 2>&1) || true

STATUS_HTTP=$(echo "$STATUS_RESPONSE" | tail -1)
if [[ "$STATUS_HTTP" == "200" ]]; then
    STATUS_BODY=$(echo "$STATUS_RESPONSE" | head -n -1)
    info "Endpoint healthy: ${STATUS_BODY}"
else
    warn "Health check returned HTTP ${STATUS_HTTP} — proceeding anyway."
fi

# ── Deploy ──────────────────────────────────────────────────────────────────

info "Deploying to ${DEPLOY_URL}..."

RESPONSE=$(curl -sS -w "\n%{http_code}" \
    -X POST "${DEPLOY_URL}" \
    -H "X-Deploy-Key: ${DEPLOY_KEY}" \
    -F "plugin=@${ZIP_PATH}" \
    --max-time 120)

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -n -1)

if [[ "$HTTP_CODE" -eq 200 ]]; then
    info "Deployment successful!"
    echo "$BODY" | python3 -m json.tool 2>/dev/null || echo "$BODY"
else
    error "Deployment FAILED (HTTP ${HTTP_CODE}):"
    echo "$BODY" | python3 -m json.tool 2>/dev/null || echo "$BODY"
    rm -rf "$BUILD_DIR"
    exit 1
fi

# ── Cleanup ─────────────────────────────────────────────────────────────────

rm -rf "$BUILD_DIR"
info "Done. AutoBlogger v${VERSION} is live."
