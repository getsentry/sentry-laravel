<?php

namespace Sentry\Laravel\Tests\Features;

use Laravel\Folio\Folio;
use Laravel\Pennant\Feature;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Laravel\Integration;
use Illuminate\Config\Repository;
use Sentry\Laravel\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class PennantPackageIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Feature::class)) {
            $this->markTestSkipped('Laravel Pennant package is not installed.');
        }

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app['config'], static function (Repository $config) {
            // Force Pennant to use the array driver instead of the database which is the default
            $config->set('pennant.default', 'array');
            $config->set('pennant.stores.array', ['driver' => 'array']);
        });
    }

    public function testPennantFeatureIsRecorded(): void
    {
        Feature::define('new-dashboard', static function () {
            return true;
        });

        Feature::active('new-dashboard');

        $scope = $this->getCurrentSentryScope();

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertArrayHasKey('flags', $event->getContexts());

        $flags = $event->getContexts()['flags']['values'];

        $this->assertEquals([
            [
                'flag' => 'new-dashboard',
                'result' => true,
            ]
        ], $flags);
    }

    public function testPennantRichFeatureIsRecordedAsActive(): void
    {
        Feature::define('dashboard-version', static function () {
            return 'dark';
        });

        Feature::value('dashboard-version');

        $scope = $this->getCurrentSentryScope();

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertArrayHasKey('flags', $event->getContexts());

        $flags = $event->getContexts()['flags']['values'];

        $this->assertEquals([
            [
                'flag' => 'dashboard-version',
                'result' => true,
            ]
        ], $flags);
    }

    public function testPennantRichFeatureIsRecordedAsInactive(): void
    {
        Feature::define('dashboard-version', static function () {
            return false;
        });

        Feature::value('dashboard-version');

        $scope = $this->getCurrentSentryScope();

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertArrayHasKey('flags', $event->getContexts());

        $flags = $event->getContexts()['flags']['values'];

        $this->assertEquals([
            [
                'flag' => 'dashboard-version',
                'result' => false,
            ]
        ], $flags);
    }
}
