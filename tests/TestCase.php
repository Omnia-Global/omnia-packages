<?php

namespace OmniaGlobal\OmniaPackages\Tests;

use OmniaGlobal\OmniaPackages\OmniaPackagesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [OmniaPackagesServiceProvider::class];
    }
}
