#!/usr/bin/env bash
# One-time (and safe to re-run) local setup for the Makuruma API on macOS.
# Usage: cd makuruma-backend && bash scripts/setup-mac.sh
set -euo pipefail
export NONINTERACTIVE=1 HOMEBREW_NO_ENV_HINTS=1
cd "$(dirname "$0")/.."

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
fail() { printf '\n\033[1;31m✖ %s\033[0m\n' "$1"; exit 1; }

step "Checking Homebrew"
command -v brew >/dev/null || fail "Homebrew is missing. Install it from https://brew.sh then re-run this script."

step "Installing PHP, Composer and MySQL if missing"
command -v php >/dev/null || brew install php@8.4
# The locked packages (phpoffice/phpspreadsheet 1.x) support PHP 8.2–8.4, not 8.5+.
if php -r 'exit(version_compare(PHP_VERSION, "8.5.0", ">=") ? 0 : 1);'; then
  echo "PHP $(php -r 'echo PHP_VERSION;') is too new for the locked packages; switching to PHP 8.4"
  brew list php@8.4 >/dev/null 2>&1 || brew install php@8.4
  brew unlink php >/dev/null 2>&1 || true
  brew link --force --overwrite php@8.4
  hash -r
fi
for pkg in composer mysql; do
  command -v "$pkg" >/dev/null || brew install "$pkg"
done
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || fail "PHP 8.2+ required (found $(php -r 'echo PHP_VERSION;')). Run: brew upgrade php"
PHP_MODULES=$(php -m)   # read once: `php -m | grep -q` can fail under pipefail (SIGPIPE)
for ext in pdo_mysql mbstring xml zip gd; do
  grep -qix "$ext" <<<"$PHP_MODULES" || fail "PHP extension '$ext' is missing. Run: brew reinstall php"
done
echo "PHP $(php -r 'echo PHP_VERSION;') OK"

step "Starting MySQL"
brew services start mysql >/dev/null 2>&1 || true
for i in {1..30}; do mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1 && break; sleep 1; done
mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1 || fail "MySQL did not start. Check: brew services list"
echo "MySQL is running"

step "Preparing .env"
[ -f .env ] || { cp .env.example .env; echo "Created .env from .env.example"; }
env_get() { { grep -E "^$1=" .env || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_HOST=$(env_get DB_HOST); DB_PORT=$(env_get DB_PORT); DB_DATABASE=$(env_get DB_DATABASE)
DB_USERNAME=$(env_get DB_USERNAME); DB_PASSWORD=$(env_get DB_PASSWORD)
echo "Database: $DB_DATABASE on $DB_HOST:$DB_PORT as $DB_USERNAME"

step "Creating the database (if it does not exist)"
MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
  -e "CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
  || fail "Could not connect to MySQL as '$DB_USERNAME'. Fix DB_USERNAME / DB_PASSWORD in .env (fresh Homebrew MySQL: root with an empty password)."

step "Installing PHP packages"
composer install --no-interaction

step "Application key"
[ -n "$(env_get APP_KEY)" ] && echo "APP_KEY already set" || php artisan key:generate

step "Migrating and seeding"
php artisan config:clear >/dev/null
php artisan migrate --seed --force

step "Linking storage for uploaded documents"
[ -L public/storage ] && echo "public/storage already linked" || php artisan storage:link

printf '\n\033[1;32m✔ Backend ready.\033[0m\n'
echo "Start it with:   php artisan serve --host=127.0.0.1 --port=8000"
ADMIN_EMAIL=$(env_get SEED_ADMIN_EMAIL)
echo "Login:           ${ADMIN_EMAIL:-admin@makuruma.local} / password (or SEED_ADMIN_PASSWORD from .env)"
