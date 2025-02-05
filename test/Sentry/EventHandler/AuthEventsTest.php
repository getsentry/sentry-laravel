<?php

namespace Sentry\Laravel\Tests\EventHandler;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Sentry\Laravel\Tests\TestCase;

class AuthEventsTest extends TestCase
{
    protected $setupConfig = [
        'sentry.send_default_pii' => true,
    ];

    public function testAuthenticatedEventFillsUserOnScope(): void
    {
        $user = new AuthEventsTestUserModel;

        $user->forceFill([
            'id' => 123,
            'username' => 'username',
            'email' => 'foo@example.com',
        ]);

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchLaravelEvent(new Authenticated('test', $user));

        $this->assertNotNull($scope->getUser());

        $this->assertEquals(123, $scope->getUser()->getId());
        $this->assertEquals('username', $scope->getUser()->getUsername());
        $this->assertEquals('foo@example.com', $scope->getUser()->getEmail());
    }

    public function testAuthenticatedEventFillsUserOnScopeWhenUsernameIsNotAString(): void
    {
        $user = new AuthEventsTestUserModel();

        $user->forceFill([
            'id' => 123,
            'username' => 456,
        ]);

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchLaravelEvent(new Authenticated('test', $user));

        $this->assertNotNull($scope->getUser());

        $this->assertEquals(123, $scope->getUser()->getId());
        $this->assertEquals('456', $scope->getUser()->getUsername());
    }

    public function testAuthenticatedEventDoesNotFillUserOnScopeWhenPIIShouldNotBeSent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => false,
        ]);

        $user = new AuthEventsTestUserModel();

        $user->id = 123;

        $scope = $this->getCurrentSentryScope();

        $this->assertNull($scope->getUser());

        $this->dispatchLaravelEvent(new Authenticated('test', $user));

        $this->assertNull($scope->getUser());
    }
}

class AuthEventsTestUserModel extends Model implements Authenticatable
{
    use \Illuminate\Auth\Authenticatable;
}
