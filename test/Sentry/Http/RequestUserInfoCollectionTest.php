<?php

namespace Sentry\Laravel\Tests\Http;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Sentry\Laravel\EventHandler;
use Sentry\Laravel\Http\SetRequestIpMiddleware;
use Sentry\Laravel\Tests\TestCase;
use Sentry\State\Scope;

class RequestUserInfoCollectionTest extends TestCase
{
    /**
     * @dataProvider userInfoPolicyProvider
     *
     * @param array<string, mixed>|null $dataCollection
     */
    public function testRequestIpUsesSharedUserInfoPolicy(?array $dataCollection, bool $sendDefaultPii, bool $expected): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => $dataCollection,
            'sentry.send_default_pii' => $sendDefaultPii,
        ]);
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);

        $this->handleIpMiddleware($request);

        $user = $this->getCurrentSentryScope()->getUser();
        $this->assertSame($expected, $user !== null);
        if ($expected) {
            $this->assertSame('192.0.2.1', $user->getIpAddress());
        }
    }

    public static function userInfoPolicyProvider(): iterable
    {
        yield 'legacy off' => [null, false, false];
        yield 'legacy on' => [null, true, true];
        yield 'configured off overrides pii' => [['user_info' => false], true, false];
        yield 'configured default overrides legacy off' => [[], false, true];
    }

    public function testTrustedProxyAwareIpIsCollected(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ]);

        try {
            $this->handleIpMiddleware($request);
            $this->assertSame('203.0.113.10', $this->getCurrentSentryScope()->getUser()->getIpAddress());
        } finally {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
    }

    public function testAuthenticationEventsUseConfiguredPolicy(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.send_default_pii' => false,
        ]);
        $user = new RequestUserInfoModel();
        $user->forceFill(['id' => 123, 'email' => 'alice@example.com']);

        $this->dispatchLaravelEvent(new Authenticated('test', $user));

        $this->assertSame(123, $this->getCurrentSentryScope()->getUser()->getId());
        $this->assertSame('alice@example.com', $this->getCurrentSentryScope()->getUser()->getEmail());
    }

    public function testDisabledCollectionAvoidsRequestAndModelAcquisition(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => ['user_info' => false],
            'sentry.send_default_pii' => true,
        ]);
        $request = new GuardedUserInfoRequest();
        $model = new GuardedUserInfoModel();

        $this->handleIpMiddleware($request);
        $this->dispatchLaravelEvent(new Authenticated('test', $model));

        $this->assertSame(0, $request->ipCalls);
        $this->assertSame(0, $model->attributeCalls);
        $this->assertNull($this->getCurrentSentryScope()->getUser());
    }

    public function testDisabledCollectionAvoidsSanctumTokenableAcquisition(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => ['user_info' => false],
            'sentry.send_default_pii' => true,
        ]);

        if (class_exists('Laravel\\Sanctum\\Events\\TokenAuthenticated')) {
            $this->markTestSkipped('The Sanctum event fixture requires Sanctum not to be installed.');
        }

        class_alias(SanctumTokenAuthenticatedFixture::class, 'Laravel\\Sanctum\\Events\\TokenAuthenticated');
        $token = new GuardedSanctumToken();
        $handler = new EventHandler($this->app, []);

        $handler->sanctumTokenAuthenticated(new SanctumTokenAuthenticatedFixture($token));

        $this->assertSame(0, $token->attributeCalls);
        $this->assertNull($this->getCurrentSentryScope()->getUser());
    }

    public function testRuntimePolicyChangesAffectSubsequentOperations(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => ['user_info' => false]]);
        $this->handleIpMiddleware(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']));
        $this->assertNull($this->getCurrentSentryScope()->getUser());

        $this->getSentryClientFromContainer()->getOptions()->getDataCollection()->setUserInfo(true);
        $this->handleIpMiddleware(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.2']));
        $this->assertSame('192.0.2.2', $this->getCurrentSentryScope()->getUser()->getIpAddress());
    }

    public function testDisabledAutomaticCollectionPreservesExplicitUser(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => ['user_info' => false]]);
        $this->getSentryHubFromContainer()->configureScope(static function (Scope $scope): void {
            $scope->setUser(['id' => 'manual']);
        });
        $user = new RequestUserInfoModel();
        $user->forceFill(['id' => 123]);

        $this->handleIpMiddleware(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']));
        $this->dispatchLaravelEvent(new Authenticated('test', $user));

        $this->assertSame('manual', $this->getCurrentSentryScope()->getUser()->getId());
    }

    private function handleIpMiddleware(Request $request): void
    {
        (new SetRequestIpMiddleware())->handle($request, static function (Request $request) {
            return $request;
        });
    }
}

class RequestUserInfoModel extends Model implements Authenticatable
{
    use \Illuminate\Auth\Authenticatable;
}

class SanctumTokenAuthenticatedFixture
{
    /** @var mixed */
    public $token;

    /** @param mixed $token */
    public function __construct($token)
    {
        $this->token = $token;
    }
}

class GuardedSanctumToken extends Model
{
    /** @var int */
    public $attributeCalls = 0;

    public function __construct(array $attributes = [])
    {
    }

    public function getAttribute($key)
    {
        ++$this->attributeCalls;

        throw new \RuntimeException('The tokenable relation must not be acquired.');
    }
}

class GuardedUserInfoRequest extends Request
{
    /** @var int */
    public $ipCalls = 0;

    public function ip()
    {
        ++$this->ipCalls;

        throw new \RuntimeException('IP must not be acquired.');
    }
}

class GuardedUserInfoModel extends RequestUserInfoModel
{
    /** @var int */
    public $attributeCalls = 0;

    public function __construct(array $attributes = [])
    {
    }

    public function getAttributes()
    {
        ++$this->attributeCalls;

        throw new \RuntimeException('Model attributes must not be acquired.');
    }
}
