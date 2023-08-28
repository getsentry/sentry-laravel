<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Artisan;
use Sentry\Client;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Version;

class AboutCommandIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AboutCommand::class)) {
            $this->markTestSkipped('The About command is only available in Laravel 9.0+');
        }

        parent::setUp();
    }

    public function testAboutCommandExpectedOutput(): void
    {
        $data = [
            'environment' => 'testing',
            'release' => '1.1.0',
            'sample_rate_profiling' => 'NOT SET',
            'sample_rate_performance_monitoring' => 1,
            'send_default_pii' => 'DISABLED',
            'php_sdk_version' => Client::SDK_VERSION,
            'laravel_sdk_version' => Version::SDK_VERSION,
        ];

        $this->resetApplicationWithConfig([
            'sentry.environment' => $data['environment'],
            'sentry.release' => $data['release'],
            'sentry.send_default_pii' => false,
            'sentry.traces_sample_rate' => 1.0,
            'sentry.profiles_sample_rate' => null,
        ]);

        Artisan::call('about --json');

        $output = Artisan::output();

        $this->assertIsString($output);

        $json = json_decode($output, true);

        $this->assertIsArray($json);

        $this->assertArrayHasKey('sentry', $json);

        $sentryData = $json['sentry'];
        
        foreach ($data as $key => $value) {
            $this->assertArrayHasKey($key, $sentryData);
            $this->assertEquals($sentryData[$key], $value);
        }
    }
}
