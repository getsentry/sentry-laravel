<?php

namespace Sentry\Console;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Artisan;
use Sentry\Client;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Laravel\Version;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

class AboutCommandIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AboutCommand::class)) {
            $this->markTestSkipped('The about command is only available in Laravel 9.0+');
        }

        parent::setUp();
    }

    public function testAboutCommandContainsExpectedData(): void
    {
        $expectedData = [
            'environment' => $environment = 'testing',
            'release' => $release = '1.2.3',
            'sample_rate' => 1.0,
            'sample_rate_profiling' => 'NOT SET',
            'sample_rate_performance_monitoring' => $tracesSampleRate = 0.997,
            'send_default_pii' => 'DISABLED',
            'php_sdk_version' => Client::SDK_VERSION,
            'laravel_sdk_version' => Version::SDK_VERSION,
        ];

        $this->resetApplicationWithConfig([
            'sentry.release' => $release,
            'sentry.environment' => $environment,
            'sentry.traces_sample_rate' => $tracesSampleRate,
        ]);

        $sentryAboutOutput = $this->runArtisanAboutAndReturnSentryData();

        foreach ($expectedData as $key => $value) {
            $this->assertArrayHasKey($key, $sentryAboutOutput);
            $this->assertEquals($value, $sentryAboutOutput[$key]);
        }
    }

    public function testAboutCommandContainsExpectedDataWithoutHubClient(): void
    {
        $expectedData = [
            'enabled' => 'NO',
            'php_sdk_version' => Client::SDK_VERSION,
            'laravel_sdk_version' => Version::SDK_VERSION,
        ];

        $this->app->bind(HubInterface::class, static function () {
            return new Hub(null);
        });

        $sentryAboutOutput = $this->runArtisanAboutAndReturnSentryData();

        foreach ($expectedData as $key => $value) {
            $this->assertArrayHasKey($key, $sentryAboutOutput);
            $this->assertEquals($value, $sentryAboutOutput[$key]);
        }
    }

    private function runArtisanAboutAndReturnSentryData(): array
    {
        $this->withoutMockingConsoleOutput();

        $this->artisan(AboutCommand::class, ['--json' => null]);

        $output = Artisan::output();

        // This might seem like a weird thing to do, but it's necessary to make sure that that the command didn't have any side effects on the container
        $this->refreshApplication();

        $aboutOutput = json_decode($output, true);

        $this->assertArrayHasKey('sentry', $aboutOutput);

        return $aboutOutput['sentry'];
    }
}
