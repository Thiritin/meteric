<?php

declare(strict_types=1);

namespace Billify\Tests;

use Billify\BillifyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BillifyServiceProvider::class];
    }
}
