<?php

namespace Sentry\Laravel\Tests\EventHandler;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Sentry\Breadcrumb;
use Sentry\Laravel\Tests\TestCase;
use function Sentry\addBreadcrumb;
use function Sentry\captureException;

class QueueEventsTest extends TestCase
{
    public function testQueueJobSetsBreadcrumbsCorrectly(): void
    {
        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertCount(0, $this->getCurrentBreadcrumbs());
    }

    public function testQueueJobThatReportsSetsBreadcrumbsCorrectly(): void
    {
        dispatch(new QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb);

        $this->assertCount(0, $this->getCurrentBreadcrumbs());
    }

    public function testQueueJobThatThrowsSetsBreadcrumbsCorrectly(): void
    {
        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb);
        } catch (Exception $e) {
            $this->assertCount(2, $this->getCurrentBreadcrumbs());

            $firstBreadcrumb = $this->getCurrentBreadcrumbs()[0];
            $this->assertEquals('queue.job', $firstBreadcrumb->getCategory());

            $secondBreadcrumb = $this->getCurrentBreadcrumbs()[1];
            $this->assertEquals('test', $secondBreadcrumb->getCategory());
        }
    }

    public function testQueueJobsWithBreadcrumbSetInBetweenIsKept(): void
    {
        dispatch(new QueueEventsTestJobWithBreadcrumb);

        addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::LEVEL_DEBUG, 'test2', 'test2'));

        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertCount(1, $this->getCurrentBreadcrumbs());
    }
}

function queueEventsTestAddTestBreadcrumb(): void
{
    addBreadcrumb(
        new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::LEVEL_DEBUG,
            'test',
            'test'
        )
    );
}

class QueueEventsTestJobWithBreadcrumb implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();
    }
}

class QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();

        captureException(new Exception('This is a test exception'));
    }
}

class QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();

        throw new Exception('This is a test exception');
    }
}
