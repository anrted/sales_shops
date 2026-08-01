#!/usr/bin/env bash
set -e

# Target directory: app_root if exists, otherwise parent dir
if [ -d "/var/www/app_root" ]; then
    ROOT_DIR="/var/www/app_root"
else
    ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi

cd "$ROOT_DIR"

TARGET_COMMIT="${1:-origin/main}"
echo "[STEP] Initializing deployment to target: $TARGET_COMMIT"

echo "[STEP] Fetching latest changes from remote..."
git fetch origin

CURRENT_COMMIT=$(git rev-parse HEAD)
echo "[STEP] Current commit: $CURRENT_COMMIT"

if [ "$TARGET_COMMIT" = "origin/main" ]; then
    TARGET_HASH=$(git rev-parse origin/main)
else
    TARGET_HASH=$(git rev-parse "$TARGET_COMMIT")
fi
echo "[STEP] Target hash: $TARGET_HASH"

if [ "$CURRENT_COMMIT" = "$TARGET_HASH" ]; then
    echo "[STEP] Already up to date at commit $CURRENT_COMMIT"
fi

echo "[STEP] Checking file diffs between $CURRENT_COMMIT and $TARGET_HASH..."
NEED_BUILD=0
NEED_FRONTEND_RESTART=0

if git diff --name-only "$CURRENT_COMMIT" "$TARGET_HASH" | grep -qE "(composer\.json|composer\.lock|docker/|docker-compose\.yml)"; then
    NEED_BUILD=1
    echo "[STEP] Changes in composer/docker files detected. Docker rebuild will be performed."
fi

if git diff --name-only "$CURRENT_COMMIT" "$TARGET_HASH" | grep -qE "^frontend/"; then
    NEED_FRONTEND_RESTART=1
    echo "[STEP] Changes in frontend files detected. Frontend restart will be performed."
fi

echo "[STEP] Checking out $TARGET_HASH..."
git checkout -f "$TARGET_HASH"

if [ "$NEED_BUILD" -eq 1 ]; then
    echo "[STEP] Rebuilding backend containers..."
    docker compose up -d --build -V backend queue scheduler || echo "[WARNING] Docker build returned non-zero code, continuing..."
fi

echo "[STEP] Running database migrations and clearing cache..."
cd "$ROOT_DIR/backend"

if [ -f .env ] && ! grep -qE '^APP_KEY=base64:' .env; then
    echo "[STEP] Generating APP_KEY..."
    php artisan key:generate --force
fi

php artisan config:clear || true
php artisan cache:clear || true
php artisan migrate --force
php artisan db:seed --force

if [ "$NEED_FRONTEND_RESTART" -eq 1 ]; then
    echo "[STEP] Restarting frontend container..."
    cd "$ROOT_DIR"
    docker compose restart frontend || echo "[WARNING] Frontend restart failed, continuing..."
fi

echo "[STEP] System update completed successfully!"
