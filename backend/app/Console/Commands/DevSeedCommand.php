<?php

namespace App\Console\Commands;

use Database\Seeders\DevelopmentDatabaseSeeder;
use Illuminate\Console\Command;

class DevSeedCommand extends Command
{
    protected $signature = 'smartgate:dev-seed';
    protected $description = 'Seed synthetic development test data (ONLY for development/testing, never production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('CRITICAL: smartgate:dev-seed is strictly forbidden in production!');
            return self::FAILURE;
        }

        if (!$this->confirm('This will insert synthetic test accounts for local QA. Proceed?', true)) {
            return self::SUCCESS;
        }

        $this->call(DevelopmentDatabaseSeeder::class);
        $this->info('✓ Synthetic development test data created successfully.');

        return self::SUCCESS;
    }
}
