<?php

namespace Database\Seeders;

use App\Models\Gate;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DevelopmentDatabaseSeeder extends Seeder
{
    /**
     * Development testing seeder.
     * MUST NEVER RUN IN PRODUCTION.
     * Uses randomized synthetic test values strictly for local QA/unit tests.
     */
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        // 1. Synthetic Admin for dev testing
        $adminEmail = 'admin.dev@test.local';
        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Development Administrator',
                'password' => Hash::make('DevAdminPassword123!'),
                'role' => User::ROLE_ADMIN,
                'status' => User::STATUS_ACTIVE,
            ]
        );

        // 2. Synthetic Security Guards for dev testing
        for ($i = 1; $i <= 4; $i++) {
            User::firstOrCreate(
                ['email' => "guard{$i}.dev@test.local"],
                [
                    'name' => "Security Officer {$i}",
                    'password' => Hash::make('DevGuardPassword123!'),
                    'role' => User::ROLE_SECURITY,
                    'status' => User::STATUS_ACTIVE,
                ]
            );
        }

        // 3. Synthetic Students for dev testing
        $gates = Gate::all();
        for ($s = 1; $s <= 10; $s++) {
            $studentEmail = "student{$s}.dev@test.local";
            $user = User::firstOrCreate(
                ['email' => $studentEmail],
                [
                    'name' => "Test Student {$s}",
                    'password' => Hash::make('DevStudentPassword123!'),
                    'role' => User::ROLE_STUDENT,
                    'status' => User::STATUS_ACTIVE,
                ]
            );

            Student::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'student_id' => 'STU' . str_pad((string) $s, 5, '0', STR_PAD_LEFT),
                    'roll_number' => 'TEST' . str_pad((string) $s, 4, '0', STR_PAD_LEFT),
                    'name' => "Test Student {$s}",
                    'year' => 2,
                    'email' => $studentEmail,
                    'phone_number' => '555000' . str_pad((string) $s, 4, '0', STR_PAD_LEFT),
                    'program' => 'B.Tech',
                    'department' => 'Computer Science',
                    'semester' => 4,
                    'batch' => '2024-2028',
                    'status' => Student::STATUS_ACTIVE,
                    'current_status' => Student::STATE_INSIDE,
                    'last_gate_id' => $gates->first()?->id,
                ]
            );
        }
    }
}
