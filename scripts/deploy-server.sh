#!/usr/bin/env bash
# Deploy the Makuruma API on the VPS (Ubuntu 24.04, Nginx, PHP 8.3-FPM, MySQL).
#
# Run it on the server from the backend folder:
#   cd /var/www/makuruma/<backend-folder>
#   bash scripts/deploy-server.sh            # deploys origin/main
#   bash scripts/deploy-server.sh my-branch  # deploys another branch
#
# What it does, in order (it stops at the first error):
#   1. Backs up the database, .env and storage/ to ~/makuruma-backups/<timestamp>/
#   2. Records the current commit so you can roll back
#   3. Fetches the branch from `origin` and resets the code to it (.env and storage/ are untouched)
#   4. composer install, shows the pending migrations, asks before running them
#   5. Seeds the border-route checkpoints, rebuilds caches, fixes permissions, reloads PHP-FPM
#   6. Makes sure the Laravel scheduler cron entry exists (auto-complete / POD alerts)
set -euo pipefail

BRANCH="${1:-main}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$HOME/makuruma-backups/$STAMP"
PHP_FPM="${PHP_FPM:-php8.3-fpm}"

step() { printf '\n\033[1;36m▶ %s\033[0m\n' "$1"; }
fail() { printf '\033[1;31m✖ %s\033[0m\n' "$1"; exit 1; }
env_get() { grep -E "^$1=" "$APP_DIR/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

cd "$APP_DIR"
[ -f artisan ] || fail "Run this from the backend folder (artisan not found in $APP_DIR)."
[ -f .env ] || fail ".env is missing in $APP_DIR — the server copy must stay in place."
git remote get-url origin >/dev/null 2>&1 || fail "No git remote 'origin'. See DEPLOY.md step 2."

step "1/6 Backup → $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"
DB_NAME="$(env_get DB_DATABASE)"; DB_USER="$(env_get DB_USERNAME)"; DB_PASS="$(env_get DB_PASSWORD)"
DB_HOST="$(env_get DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
MYSQL_PWD="$DB_PASS" mysqldump -h "$DB_HOST" -u "$DB_USER" --single-transaction --routines --no-tablespaces "$DB_NAME" > "$BACKUP_DIR/$DB_NAME.sql" \
  || fail "Database backup failed — nothing was changed."
cp .env "$BACKUP_DIR/backend.env"
tar -czf "$BACKUP_DIR/storage.tgz" storage/app
git rev-parse HEAD > "$BACKUP_DIR/previous-commit.txt" 2>/dev/null || echo "none" > "$BACKUP_DIR/previous-commit.txt"
echo "  database: $(du -h "$BACKUP_DIR/$DB_NAME.sql" | cut -f1)  previous commit: $(cat "$BACKUP_DIR/previous-commit.txt")"

step "2/6 Code: origin/$BRANCH ($(git remote get-url origin))"
git fetch origin "$BRANCH"
git checkout -B "$BRANCH" "origin/$BRANCH"
git reset --hard "origin/$BRANCH"
git log --oneline -1

step "3/6 Dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

step "4/6 Database migrations"
php artisan migrate:status | grep -i pending || true
PENDING="$(php artisan migrate:status | grep -ci pending || true)"
if [ "$PENDING" -gt 0 ]; then
  read -r -p "  Run $PENDING pending migration(s) now? Backup is in $BACKUP_DIR [y/N] " answer
  [[ "$answer" =~ ^[Yy]$ ]] || fail "Stopped before migrating. Code is updated; run 'php artisan migrate --force' when ready."
  php artisan migrate --force
else
  echo "  nothing to migrate"
fi
php artisan db:seed --class=CheckpointSeeder --force

step "5/6 Caches, storage link, permissions, PHP-FPM"
php artisan storage:link 2>/dev/null || true
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo chown -R "$(whoami)":www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo systemctl reload "$PHP_FPM"

step "6/6 Scheduler (hourly tracking checks)"
CRON_LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
if crontab -l 2>/dev/null | grep -Fq "$APP_DIR && php artisan schedule:run"; then
  echo "  cron entry already present"
else
  (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -
  echo "  added: $CRON_LINE"
fi
php artisan schedule:list | grep -i tracking || true

printf '\n\033[1;32m✔ API deployed (%s).\033[0m Backup: %s\n' "$(git rev-parse --short HEAD)" "$BACKUP_DIR"
echo "  Roll back: see DEPLOY.md → Rollback (restore $BACKUP_DIR/$DB_NAME.sql and reset to $(cat "$BACKUP_DIR/previous-commit.txt"))."
