---
name: laravel-api
description: Conventions for the Makuruma Laravel 12 REST API (Sanctum tokens, MySQL, controllers, models, migrations, seeders, exports, scheduler, notifications). Use for any backend change or when the web app needs a new endpoint, field or rule.
---

# Makuruma Laravel API

## Stack

PHP 8.2+, Laravel 12, MySQL 8 (local: `makuruma` db), Sanctum bearer tokens, maatwebsite/excel for XLSX/CSV, barryvdh/laravel-dompdf for PDFs.

## Layout

| Concern | Where |
| --- | --- |
| Routes | `routes/api.php` — public `login`/`register`, everything else in `auth:sanctum` group |
| Controllers | `app/Http/Controllers/*Controller.php` (`apiResource` style) |
| Business rules shared by controllers/scheduler | `app/Services/*` |
| Models | `app/Models/*` (`$fillable`, `casts()`, relations) |
| Excel/CSV | `app/Exports/*` (and `app/Imports/*` for bulk import) |
| Schedule | `routes/console.php` (`Schedule::command(...)`) |
| Seeders | `database/seeders` — `DatabaseSeeder` calls Role, Checkpoint, admin user, Fleet (local) |

## Conventions

- Validate in the controller with `$request->validate([...])` (or a Form Request for large payloads). Return JSON with the loaded relations the UI needs.
- Wrap multi-row writes (booking + trucks, bulk import) in `DB::transaction`.
- Cross-record rules (trailer+driver required, location dates in route order, no duplicate truck in a booking) live in a service and are called from every write path, including imports.
- Time-based rules are idempotent artisan commands scheduled in `routes/console.php`; locally run `php artisan schedule:work`.
- Notifications: Laravel database notifications (`notifications` table) to users by role slug (e.g. `accountant`). In-app only unless approved.
- Files: store on the `local`/`public` disk via `Storage`; expose download/view through an authenticated endpoint; deleting a document removes the file and the row.
- Roles: `users.role_id` → `roles.slug` in `admin | manager | accountant | operator | driver`. Check with `$request->user()->role?->slug`.
- Migrations: one concern per migration, `down()` must reverse it.

## Verify

```bash
php artisan migrate:fresh --seed
php artisan route:list --path=api
php artisan test
```
