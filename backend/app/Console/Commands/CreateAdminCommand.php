<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdminCommand extends Command
{
    protected $signature = 'smartgate:install-admin {--name= : Admin full name} {--email= : Admin email address} {--password= : Admin secure password}';
    protected $description = 'Bootstrap the initial SmartGate Administrator account';

    public function handle(): int
    {
        $this->info('=== SmartGate Administrator Setup ===');

        $name = $this->option('name') ?: text(
            label: 'Enter Administrator Name:',
            required: true,
            validate: fn(string $value) => strlen($value) >= 2 ? null : 'Name must be at least 2 characters.'
        );

        $email = $this->option('email') ?: text(
            label: 'Enter Administrator Email:',
            required: true,
            validate: fn(string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address.'
        );

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email [{$email}] already exists.");
            return self::FAILURE;
        }

        $pwd = $this->option('password') ?: password(
            label: 'Enter Secure Password (minimum 8 characters):',
            required: true,
            validate: fn(string $value) => strlen($value) >= 8 ? null : 'Password must be at least 8 characters.'
        );

        $admin = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($pwd),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'ADMIN_BOOTSTRAP',
            'module' => 'AUTH',
            'entity_type' => User::class,
            'entity_id' => (string) $admin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'CLI',
            'status' => AuditLog::STATUS_SUCCESS,
            'metadata' => ['email' => $email],
            'server_timestamp' => now('Asia/Kolkata'),
        ]);

        $this->newLine();
        $this->info("✓ Administrator [{$name}] ({$email}) successfully created!");
        $this->info("You can now log in to the SmartGate Admin Dashboard.");

        return self::SUCCESS;
    }
}
