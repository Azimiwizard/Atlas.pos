<?php

namespace App\Console\Commands;

use App\Support\Environment\EnvironmentHealth;
use Illuminate\Console\Command;

class FinanceEnvCheck extends Command
{
    protected $signature = 'finance:env-check';

    protected $description = 'Inspect cache, session, and queue drivers required by finance dashboards.';

    public function handle(EnvironmentHealth $health): int
    {
        $this->line('Finance environment diagnostics:');

        if ($health->redisAvailable()) {
            $this->info('Redis connection: OK');
        } else {
            $this->warn('Redis connection: missing. Falling back to local drivers.');
        }

        if ($health->cacheSupportsTags()) {
            $this->info('Cache tagging: supported');
        } else {
            $this->warn('Cache tagging: not supported. Finance caches may bypass tenant tags.');
        }

        if ($health->usedFallbackDrivers()) {
            $this->warn('Notice: application is currently using file cache/session or sync queue fallbacks.');
        }

        return self::SUCCESS;
    }
}
