#!/usr/bin/env bash
# Deploy the Makuruma API on the VPS (Ubuntu 24.04, Nginx, PHP 8.3-FPM, MySQL).
#
# Run it on the server from the backend folder:
#   cd /var/www/makuruma/<backend-folder>
#   bash scripts/deploy-server.sh            # deploys origin/main
# The script updates itself in step 2, so run a copy to be safe:
#   cp scripts/deploy-server.sh /tmp/ && APP_DIR=$PWD bash /tmp/deploy-server.sh
#   bash scripts/deploy-server.sh my-branch  # deploys another branch
#
# What it does, in order (it stops at the first error):
#   1. Backs up the database, .env and storage/ to ~/makuruma-backups/<timestamp>/
#   2. Records the current commit so you can roll back
#   3. Fetches the branch from `origin` and resets the code to it (.env and storage/ are untouched)
#   4. composer install; rehearses the migrations on a copy of the database and checks no rows
#      are lost; only then asks before migrating the live database (in maintenance mode)
#   5. Seeds the border-route checkpoints, rebuilds caches, fixes permissions, reloads PHP-FPM
#   6. Makes sure the Laravel scheduler cron entry exists (auto-complete / POD alerts)
set -euo pipefail

BRANCH="${1:-main}"
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$HOME/makuruma-backups/$STAMP"
PHP_FPM="${PHP_FPM:-php8.3-fpm}"

# Files stay owned by the normal server user even when this runs as root (e.g. Hostinger's browser terminal).
OWNER="${OWNER:-$( [ "$(id -u)" = 0 ] && echo collins || whoami )}"
if [ "$(id -u)" = 0 ]; then
  export COMPOSER_ALLOW_SUPERUSER=1
  git config --global --get-all safe.directory | grep -qx "$APP_DIR" || git config --global --add safe.directory "$APP_DIR"
fi

# Admin MySQL login for the rehearsal database: Ubuntu's maintenance account if readable, else root via socket.
mysql_admin() {
  if [ -r /etc/mysql/debian.cnf ]; then mysql --defaults-file=/etc/mysql/debian.cnf "$@"; else sudo mysql "$@"; fi
}

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

step "4/6 Database migrations (rehearsal on a copy first, then live)"
php artisan config:clear >/dev/null
PENDING="$(php artisan migrate:status | grep -ci pending || true)"
if [ "$PENDING" -gt 0 ]; then
  php artisan migrate:status | grep -i pending

  # Rehearsal: load the backup into a throw-away database and migrate that copy.
  # The live database is not touched unless the copy migrates cleanly and nothing is lost.
  REHEARSAL_DB="${DB_NAME}_v2_rehearsal"
  echo "  rehearsal: copying the backup into $REHEARSAL_DB …"
  mysql_admin -e "DROP DATABASE IF EXISTS \`$REHEARSAL_DB\`; CREATE DATABASE \`$REHEARSAL_DB\`;"
  for HOST in $(mysql_admin -N -e "SELECT host FROM mysql.user WHERE user='$DB_USER'"); do
    mysql_admin -e "GRANT ALL PRIVILEGES ON \`$REHEARSAL_DB\`.* TO '$DB_USER'@'$HOST'; FLUSH PRIVILEGES;"
  done
  mysql_admin "$REHEARSAL_DB" < "$BACKUP_DIR/$DB_NAME.sql"
  count_rows() {
    mysql_admin -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$1' AND table_type='BASE TABLE' AND table_name NOT IN ('migrations','notifications','tracking_statuses','cache','cache_locks','sessions','jobs','failed_jobs','personal_access_tokens')" \
      | sort | while read -r t; do echo "$t $(mysql_admin -N -e "SELECT COUNT(*) FROM \`$1\`.\`$t\`")"; done
  }
  count_rows "$REHEARSAL_DB" > "$BACKUP_DIR/rows-before.txt"
  DB_DATABASE="$REHEARSAL_DB" php artisan migrate --force || { mysql_admin -e "DROP DATABASE \`$REHEARSAL_DB\`"; fail "Rehearsal migration FAILED on the copy. The live database was NOT changed. Send this output to support."; }
  count_rows "$REHEARSAL_DB" > "$BACKUP_DIR/rows-after.txt"
  mysql_admin -e "DROP DATABASE \`$REHEARSAL_DB\`"
  # Every table that existed before must still have at least as many rows (new rows are fine,
  # e.g. the Sakania Zambia checkpoint).
  LOST="$(awk 'NR==FNR { before[$1]=$2; next } { after[$1]=$2 }
    END { for (t in before) if (!(t in after) || after[t] < before[t]) print t": "before[t]" → "(t in after ? after[t] : "missing") }' \
    "$BACKUP_DIR/rows-before.txt" "$BACKUP_DIR/rows-after.txt")"
  [ -z "$LOST" ] || { echo "$LOST"; fail "Rows would be lost (see above). The live database was NOT changed."; }
  echo "  rehearsal OK: no table lost a row ($(wc -l < "$BACKUP_DIR/rows-before.txt") tables checked)"

  read -r -p "  Run the $PENDING migration(s) on the LIVE database now? Backup is in $BACKUP_DIR [y/N] " answer
  [[ "$answer" =~ ^[Yy]$ ]] || fail "Stopped before migrating. Code is updated; run this script again when ready."
  php artisan down --retry=60 || true
  php artisan migrate --force || { php artisan up; fail "Live migration failed. Restore: see DEPLOY.md → Rollback (backup $BACKUP_DIR)."; }
  count_rows "$DB_NAME" > "$BACKUP_DIR/rows-live-after.txt"
  diff "$BACKUP_DIR/rows-after.txt" "$BACKUP_DIR/rows-live-after.txt" >/dev/null || echo "  note: live row counts differ from the rehearsal (new data arrived while deploying)"
  php artisan up
else
  echo "  nothing to migrate"
fi
php artisan db:seed --class=CheckpointSeeder --force

step "5/6 Caches, storage link, permissions, PHP-FPM"
[ -e public/storage ] || php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo chown -R "$OWNER":www-data "$APP_DIR"
sudo chmod -R ug+rwX storage bootstrap/cache
sudo systemctl reload "$PHP_FPM"

step "6/6 Scheduler (hourly tracking checks)"
CRON_LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
CRONTAB="crontab -u $OWNER"
[ "$(id -u)" = 0 ] || CRONTAB="crontab"
if { $CRONTAB -l 2>/dev/null || true; } | grep -Fq "$APP_DIR && php artisan schedule:run"; then
  echo "  cron entry already present"
else
  { $CRONTAB -l 2>/dev/null || true; echo "$CRON_LINE"; } | $CRONTAB -
  echo "  added: $CRON_LINE"
fi
php artisan schedule:list | grep -i tracking || true
sudo chown -R "$OWNER":www-data "$APP_DIR"

printf '\n\033[1;32m✔ API deployed (%s).\033[0m Backup: %s\n' "$(git rev-parse --short HEAD)" "$BACKUP_DIR"
echo "  Roll back: see DEPLOY.md → Rollback (restore $BACKUP_DIR/$DB_NAME.sql and reset to $(cat "$BACKUP_DIR/previous-commit.txt"))."
