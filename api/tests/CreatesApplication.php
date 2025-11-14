<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->configureTestingDatabase($app);

        return $app;
    }

    protected function configureTestingDatabase(Application $app): void
    {
        if ($this->supportsSqlite()) {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.database', ':memory:');
        } else {
            $app['config']->set('database.default', env('DB_CONNECTION', 'pgsql'));
        }
    }

    protected function supportsSqlite(): bool
    {
        return extension_loaded('pdo_sqlite');
    }
}
