<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Sentry\Event;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;

class OctaneIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(RequestReceived::class) || !class_exists(RequestTerminated::class)) {
            $this->markTestSkipped('Laravel Octane package is not installed.');
        }

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Bind a dummy octane instance to enable octane handlers
        $app->instance('octane', new \stdClass());
    }

    protected function tearDown(): void
    {
        Integration::setTransaction(null);

        parent::tearDown();
    }

    public function testOctaneRequestTerminationClearsStaticTransaction(): void
    {
        $request = Request::create('/octane-previous-request', 'GET');

        $this->dispatchLaravelEvent(new RequestReceived($this->app, $this->app, $request));
        $this->dispatchLaravelEvent(new RouteMatched(
            new Route('GET', '/octane-previous-request', []),
            $request
        ));

        $this->assertSame('/octane-previous-request', Integration::getTransaction());

        $eventBeforeTermination = $this->getCurrentSentryScope()->applyToEvent(Event::createEvent());

        $this->assertNotNull($eventBeforeTermination);
        $this->assertSame('/octane-previous-request', $eventBeforeTermination->getTransaction());

        $this->dispatchLaravelEvent(new RequestTerminated(
            $this->app,
            $this->app,
            $request,
            new Response('ok', 200)
        ));

        $eventAfterTermination = $this->getCurrentSentryScope()->applyToEvent(Event::createEvent());

        $this->assertNotNull($eventAfterTermination);
        $this->assertNull(Integration::getTransaction());
        $this->assertNull($eventAfterTermination->getTransaction());
    }
}
