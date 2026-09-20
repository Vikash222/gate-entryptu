<?php

namespace Database\Seeders;

use App\Models\Gate;
use App\Models\MovementOption;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * STRICT NO-DEMO-DATA RULE: Zero students, zero guards, zero movements, zero credentials.
     * Only baseline system configuration (gates and movement options) are seeded.
     */
    public function run(): void
    {
        // 1. Initial Gate Configuration
        $gates = [
            ['name' => 'Gate 1', 'code' => 'GATE-1', 'status' => Gate::STATUS_ACTIVE, 'location' => 'Main University Entrance'],
            ['name' => 'Gate 2', 'code' => 'GATE-2', 'status' => Gate::STATUS_ACTIVE, 'location' => 'North Campus Gate'],
        ];

        foreach ($gates as $gateData) {
            Gate::firstOrCreate(['code' => $gateData['code']], $gateData);
        }

        // 2. Initial Configurable Movement Destinations
        $destinations = [
            'Jalandhar',
            'Kapurthala',
            'Kheere Shop',
            'Home',
            'Other',
        ];

        foreach ($destinations as $index => $dest) {
            MovementOption::firstOrCreate(
                ['type' => MovementOption::TYPE_DESTINATION, 'name' => $dest],
                ['sort_order' => $index + 1, 'is_active' => true]
            );
        }

        // 3. Initial Configurable Movement Purposes
        $purposes = [
            'Personal',
            'Food',
            'Shopping',
            'Academic',
            'Medical',
            'Home Visit',
            'Other',
        ];

        foreach ($purposes as $index => $purpose) {
            MovementOption::firstOrCreate(
                ['type' => MovementOption::TYPE_PURPOSE, 'name' => $purpose],
                ['sort_order' => $index + 1, 'is_active' => true]
            );
        }
    }
}
