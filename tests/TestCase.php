<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected $connectionsToTransact = ['landlord'];

    protected function setUp(): void
    {
        parent::setUp();

        if (str_contains(config('app.url'), '.test')) {
            config(['session.domain' => '.starterkit-v2.test']);
            config(['app.url' => 'http://starterkit-v2.test']);
        }
    }
}
