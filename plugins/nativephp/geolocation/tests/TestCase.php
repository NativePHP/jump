<?php

namespace Tests;

use Native\Mobile\NativeServiceProvider;
use Native\Mobile\Providers\GeolocationServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Bootstraps a minimal Laravel app (Testbench) with the NativePHP core
 * provider — which loads the nativephp_call() polyfill and the Geolocation
 * facade — plus this plugin's provider. The Geolocation PHP facade lives in
 * nativephp/mobile; these tests assert that calling it fires THIS plugin's
 * bridge functions with the expected payloads (the PHP → native contract),
 * caught via the FakeBridge without a device.
 */
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            NativeServiceProvider::class,
            GeolocationServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('nativephp.app_id', 'com.test.app');
        $app['config']->set('nativephp.version', '1.0.0');
        $app['config']->set('nativephp.version_code', 1);
        $app['config']->set('app.name', 'Test App');
    }
}
