<?php

namespace Database\Seeders;

use App\Models\Checkpoint;
use Illuminate\Database\Seeder;

class CheckpointSeeder extends Seeder
{
    public function run(): void
    {
        // route: null = on both border routes; Sakania replaces Kasumbalesa when used (contract 2.2 D-3).
        $checkpoints = [
            ['name' => 'Tunduma', 'sequence_order' => 1, 'route' => null],
            ['name' => 'Nakonde', 'sequence_order' => 2, 'route' => null],
            ['name' => 'Kasumbalesa Zambia', 'sequence_order' => 3, 'route' => 'kasumbalesa'],
            ['name' => 'Sakania Zambia', 'sequence_order' => 3, 'route' => 'sakania'],
            ['name' => 'Kasumbalesa DRC', 'sequence_order' => 4, 'route' => 'kasumbalesa'],
            ['name' => 'Sakania DRC', 'sequence_order' => 4, 'route' => 'sakania'],
            ['name' => 'Whisky', 'sequence_order' => 5, 'route' => null],
        ];

        foreach ($checkpoints as $cp) {
            Checkpoint::updateOrCreate(['name' => $cp['name']], $cp);
        }
    }
}