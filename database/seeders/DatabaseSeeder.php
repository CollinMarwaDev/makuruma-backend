<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed reference data and a first admin account.
     * Credentials come from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD (see .env.example).
     */
    public function run(): void
    {
        $this->call([RoleSeeder::class, CheckpointSeeder::class]);

        User::firstOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@makuruma.local')],
            [
                'name' => 'System Admin',
                'password' => env('SEED_ADMIN_PASSWORD', 'password'),
                'role_id' => Role::where('slug', 'admin')->value('id'),
            ],
        );

        // Demo data (max 5 of each kind) only on a developer machine, never on the live server.
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
