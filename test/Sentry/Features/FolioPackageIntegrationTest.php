<?php

namespace Sentry\Features;

use Laravel\Folio\Folio;
use Sentry\Laravel\Integration;
use Sentry\Laravel\Tests\TestCase;

class FolioPackageIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Folio::class)) {
            $this->markTestSkipped('Laravel Folio package is not installed.');
        }

        parent::setUp();
    }

    protected function defineRoutes($router): void
    {
        Folio::path(__DIR__ . '/../../stubs/folio')->uri('/folio');
    }

    public function testFolioBreadcrumbIsRecorded(): void
    {
        $this->get('/folio');

        $this->assertCount(1, $this->getCurrentBreadcrumbs());

        $lastBreadcrumb = $this->getLastBreadcrumb();

        $this->assertEquals('folio.route', $lastBreadcrumb->getCategory());
        $this->assertEquals('navigation', $lastBreadcrumb->getType());
        $this->assertEquals('/folio/index', $lastBreadcrumb->getMessage());
    }

    public function testFolioRouteUpdatesIntegrationTransaction(): void
    {
        $this->get('/folio');

        $this->assertEquals('/folio/index', Integration::getTransaction());
    }

    public function testFolioTransactionNameForRouteWithSingleSegmentParamater(): void
    {
        $this->get('/folio/users/123');

        $this->assertEquals('/folio/users/{id}', Integration::getTransaction());
    }

    public function testFolioTransactionNameForRouteWithMultipleSegmentParameter(): void
    {
        $this->get('/folio/posts/1/2/3');

        $this->assertEquals('/folio/posts/{...ids}', Integration::getTransaction());
    }
}
