# Deploying Makuruma V2 to the VPS

V2 went live on 2026-11-10 (backend `4674021`+, web `84cb971`). Server git configs use `core.fileMode=false`; the pre-V2 server edits are in `git stash` (backend).

Server: Hostinger KVM 2, Ubuntu 24.04, `145.223.118.103`, user `collins` (in `www-data`), apps under `/var/www/makuruma/`.
Sites: web app `https://makurmalogstics.com`, API `https://api.makurmalogstics.com`. PHP 8.3-FPM, Nginx, MySQL user `makuruma_user`.

The live data (database, uploaded files in `storage/`, the server `.env` files) lives on the VPS, not on GitHub.
GitHub only delivers code, so moving to a new GitHub account loses nothing.

## 0. On your Mac: put V2 on `main` and push to the new account

In each repo (`makuruma-backend`, `makuruma-app`):

```bash
git remote -v                                   # must show your NEW account
# if it still shows the old one:
git remote set-url origin https://github.com/<new-account>/<repo>.git

git checkout main
git merge --ff-only feature/contract-booking-tracking
git push -u origin main
```

Check on github.com that `.env` is **not** in either repo.

## 1. On the server: connect to the new repos (once)

```bash
ssh collins@145.223.118.103
cd /var/www/makuruma && ls          # note the backend and frontend folder names
```

GitHub allows one deploy key per repo, so make one key per repo:

```bash
ssh-keygen -t ed25519 -N "" -f ~/.ssh/makuruma_backend
ssh-keygen -t ed25519 -N "" -f ~/.ssh/makuruma_app
cat ~/.ssh/makuruma_backend.pub     # → backend repo › Settings › Deploy keys › Add (read-only)
cat ~/.ssh/makuruma_app.pub         # → app repo › Settings › Deploy keys › Add (read-only)
```

Add to `~/.ssh/config`:

```
Host github-makuruma-backend
  HostName github.com
  User git
  IdentityFile ~/.ssh/makuruma_backend
  IdentitiesOnly yes

Host github-makuruma-app
  HostName github.com
  User git
  IdentityFile ~/.ssh/makuruma_app
  IdentitiesOnly yes
```

Point each folder at the new repo and remove the old account's access:

```bash
cd /var/www/makuruma/<backend-folder>
git rev-parse HEAD > ~/makuruma-backend-before-v2.txt      # rollback point
git remote set-url origin git@github-makuruma-backend:<new-account>/makuruma-backend.git
git fetch origin                                          # must succeed

cd /var/www/makuruma/<frontend-folder>
git rev-parse HEAD > ~/makuruma-app-before-v2.txt
git remote set-url origin git@github-makuruma-app:<new-account>/makuruma-app.git
git fetch origin
```

Delete any old key or token for the suspended account (`~/.ssh/`, `~/.git-credentials`).

The new repos have a different history from the old ones. That is fine: the deploy resets the files to the new `main`.
Ignored files (`.env`, `storage/`, `vendor/`, `node_modules/`) stay in place.

## 2. Deploy the API

```bash
cd /var/www/makuruma/<backend-folder>
git fetch origin && git show origin/main:scripts/deploy-server.sh > /tmp/deploy-backend.sh
APP_DIR=$PWD bash /tmp/deploy-backend.sh   # the script replaces itself while it runs, so run a copy
```

The script:

1. Backs up the database, `.env` and uploads to `~/makuruma-backups/<time>/`.
2. Updates the code to `origin/main`.
3. Runs `composer install`.
4. **Rehearses the migrations on a copy of the live database** and checks that no table lost a row.
   If the copy fails, it stops and the live database is not touched.
   Only then does it ask before migrating the live database, with the site in maintenance mode for those seconds.
5. Seeds the border checkpoints (Sakania), then rebuilds the caches.
6. Fixes `storage/` permissions and reloads `php8.3-fpm`.
7. Adds the scheduler cron line (hourly auto-complete and POD checks).

V2 adds 5 migrations (per-truck booking details, tracking statuses, tracking per leg, border routes, POD audit and notifications).

- **Old trips:** trips offloaded more than 5 days before the deploy are closed quietly. The 5-day alerts only apply from deployment day on.
- **Checkpoint dates:** existing checkpoint dates move to each truck's latest booking leg.
- **Nothing is deleted:** the migrations only add tables and columns, and fill them in. All existing rows are kept.

## 3. Deploy the web app

The app now uses **pnpm** (pinned in `package.json`). `corepack`, which comes with Node 18+, provides it.

```bash
cd /var/www/makuruma/<frontend-folder>
git fetch origin && git show origin/main:scripts/deploy-server.sh > /tmp/deploy-app.sh
APP_DIR=$PWD bash /tmp/deploy-app.sh
```

The build goes to `dist/` in that folder. If Nginx serves a different folder, find it and pass it:

```bash
sudo nginx -T | grep -n "server_name\|root"
WEB_ROOT=/path/nginx/serves bash scripts/deploy-server.sh
```

Nginx needs the single-page-app fallback, so that refreshing `/tracking` works:

```
location / { try_files $uri $uri/ /index.html; }
```

If the server runs out of memory during `pnpm build`, build on your Mac (`pnpm build`) and upload `dist/`:

```bash
rsync -av --delete dist/ collins@145.223.118.103:/var/www/makuruma/<frontend-folder>/dist/
```

## 4. Check

- Log in at `https://makurmalogstics.com`. Open Bookings, Tracking, Invoices and Expenses, and a few existing records.
- In Tracking, open a leg and save a status.
- In Invoices, open one (View).
- As admin, check that **Tracking › Manage statuses** opens.
- On the server:
  - `php artisan schedule:list` shows `tracking:daily-checks`.
  - `tail -f storage/logs/laravel.log` shows no new errors.

## Rollback

```bash
# API
cd /var/www/makuruma/<backend-folder>
B=~/makuruma-backups/<time>                     # folder printed by the deploy script
mysql -u makuruma_user -p <db_name> < $B/<db_name>.sql
git reset --hard $(cat $B/previous-commit.txt)  # or ~/makuruma-backend-before-v2.txt
composer install --no-dev --optimize-autoloader
php artisan optimize:clear && php artisan config:cache && php artisan route:cache
sudo systemctl reload php8.3-fpm

# Web app
cd /var/www/makuruma/<frontend-folder>
git reset --hard $(cat ~/makuruma-app-before-v2.txt)
rm -rf dist && tar -xzf ~/makuruma-frontend-dist-<time>.tgz   # previous build, saved by the script
```

The old commit hashes only exist on the server now (the old GitHub repos are gone), so keep the `*-before-v2.txt` files.
