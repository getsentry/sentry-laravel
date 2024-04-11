<?php

namespace Sentry\Integration;

use Illuminate\Database\Eloquent\Model;
use Sentry\Laravel\Integration;
use Sentry\Laravel\Tests\TestCase;

/**
 * Since large parts of the violation reporters are shared between the different types of violations,
 * we try to test only a single type of violation reporter to keep the test cases a bit smaller when possible.
 */
class ModelViolationReportersTest extends TestCase
{
    public function testModelViolationReportersCanBeRegistered(): void
    {
        $this->expectNotToPerformAssertions();

        Model::handleLazyLoadingViolationUsing(Integration::lazyLoadingViolationReporter());
        Model::handleMissingAttributeViolationUsing(Integration::missingAttributeViolationReporter());
        Model::handleDiscardedAttributeViolationUsing(Integration::discardedAttributeViolationReporter());
    }

    public function testViolationReporterPassesThroughToCallback(): void
    {
        $callbackCalled = false;

        $reporter = Integration::missingAttributeViolationReporter(static function () use (&$callbackCalled) {
            $callbackCalled = true;
        }, false, false);

        $reporter(new ViolationReporterTestModel, 'attribute');

        $this->assertTrue($callbackCalled);
    }

    public function testViolationReporterIsNotReportingDuplicateEvents(): void
    {
        $reporter = Integration::missingAttributeViolationReporter(null, true, false);

        $reporter(new ViolationReporterTestModel, 'attribute');
        $reporter(new ViolationReporterTestModel, 'attribute');

        $this->assertCount(1, $this->getCapturedSentryEvents());
    }

    public function testViolationReporterIsReportingDuplicateEventsIfConfigured(): void
    {
        $reporter = Integration::missingAttributeViolationReporter(null, false, false);

        $reporter(new ViolationReporterTestModel, 'attribute');
        $reporter(new ViolationReporterTestModel, 'attribute');

        $this->assertCount(2, $this->getCapturedSentryEvents());
    }
}

class ViolationReporterTestModel extends Model
{
}
