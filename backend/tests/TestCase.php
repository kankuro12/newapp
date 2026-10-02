<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $database = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');
        if (! in_array($database, [':memory:', 'business_book_testing'], true)) {
            throw new \RuntimeException('Refusing destructive tests outside business_book_testing.');
        }

        return $app;
    }
}
