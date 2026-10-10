<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static mixed $databaseLock = null;

    public function createApplication()
    {
        $app = parent::createApplication();
        $database = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');
        if (! in_array($database, [':memory:', 'business_book_testing'], true)) {
            throw new \RuntimeException('Refusing destructive tests outside business_book_testing.');
        }

        if ($database === 'business_book_testing' && ! is_resource(self::$databaseLock)) {
            $lock = fopen($app->storagePath('framework/testing-suite.lock'), 'c');
            if ($lock === false || ! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock shared test database.');
            }
            // Hold until this PHP process exits; concurrent posting workers do not migrate.
            self::$databaseLock = $lock;
        }

        return $app;
    }
}
