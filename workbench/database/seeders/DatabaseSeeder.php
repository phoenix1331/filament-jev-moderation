<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\User;

/**
 * Demo data for the workbench panel and screenshots. Randomness is seeded
 * so regenerated screenshots only change when the UI does.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(1331);

        User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
        ]);
    }
}
