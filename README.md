# Makuruma Logistics – API

Laravel 12 REST API for the Makuruma Logistics Management System (Sanctum bearer tokens, MySQL).

Full setup and run instructions for both the API and the UI: see [`../README.md`](../README.md).

Quick start (after MySQL is set up):

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed && php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

- Routes: `routes/api.php` (all under `/api`, auth via `auth:sanctum`).
- Seeders: `RoleSeeder` (admin, manager, accountant, operator, driver), `CheckpointSeeder`, demo `FleetSeeder` (local only).
- Exports: `app/Exports` (maatwebsite/excel); PDFs via barryvdh/laravel-dompdf.
