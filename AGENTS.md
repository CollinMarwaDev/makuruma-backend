# Agent guide — Makuruma Logistics API

Short routing index for agents working on the Laravel API.

1. Load `.agents/skills/laravel-api/SKILL.md` first.
2. Product context and contract scope: `../makuruma-app/.agents/skills/project-overview/SKILL.md` and `../Makuruma_Logistics_Enhancement_Contract_5.pdf`.
3. Feature workflow and gates: `../makuruma-app/spec-driven/workflow.md` (shared with the web app). Before multi-file or data-model changes, post the change list from `../makuruma-app/AGENTS.md` and wait for approval.
4. Setup and run: `../README.md`.

## Non-negotiables

- Business rules that must always hold are enforced here (validation / services), not only in the UI.
- Every schema change is a new, reversible migration; never edit a migration that has run in production.
- Back up production data before deploying migrations.
- Verify with `php artisan test` and `php artisan migrate:fresh --seed` on a local database.
