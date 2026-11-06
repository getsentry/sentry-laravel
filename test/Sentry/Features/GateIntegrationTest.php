<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate as BaseGate;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Sentry\Laravel\Features\Gate\TracingGate;
use Sentry\Laravel\Features\GateIntegration;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

class GateIntegrationTest extends TestCase
{
    protected $defaultSetupConfig = [
        'sentry.tracing.gates' => true,
    ];

    public function testGateIsReplacedWithTracingGate(): void
    {
        $this->assertInstanceOf(TracingGate::class, $this->app->make(GateContract::class));
    }

    public function testGateIsNotReplacedWhenTracingIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.gates' => false,
        ]);

        $this->assertNotInstanceOf(TracingGate::class, $this->app->make(GateContract::class));
    }

    public function testGateIsNotReplacedWithoutDsn(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => null,
            'sentry_test.override_dsn' => true,
        ]);

        $this->assertNotInstanceOf(TracingGate::class, $this->app->make(GateContract::class));
    }

    public function testGateIsReplacedWithOnlySpotlightEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => null,
            'sentry_test.override_dsn' => true,
            'sentry.spotlight' => true,
        ]);

        $this->assertInstanceOf(TracingGate::class, $this->app->make(GateContract::class));
    }

    public function testCustomGateIsNotReplaced(): void
    {
        $this->app->singleton(GateContract::class, function ($app) {
            return new GateIntegrationTestCustomGate($app, static function () {
                return null;
            });
        });

        $gate = $this->app->make(GateContract::class);

        $this->assertInstanceOf(GateIntegrationTestCustomGate::class, $gate);
        $this->assertNotInstanceOf(TracingGate::class, $gate);
    }

    public function testGateIsNotReplacedWhenResolvedBeforeRegistration(): void
    {
        // Simulate the gate being resolved by another provider before the integration registers
        $this->app->forgetExtenders(GateContract::class);
        $this->app->forgetInstance(GateContract::class);

        $gate = $this->app->make(GateContract::class);

        (new GateIntegration($this->app))->register();

        $this->assertNotInstanceOf(TracingGate::class, $gate);
        $this->assertSame($gate, $this->app->make(GateContract::class));
    }

    public function testSpanIsRecordedForAllowedCheck(): void
    {
        Gate::define('view-dashboard', static function ($user = null) {
            return true;
        });

        $transaction = $this->startTransaction();

        $this->assertTrue(Gate::allows('view-dashboard'));

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals('gate.check', $span->getOp());
        $this->assertEquals('auto.laravel.gate', $span->getOrigin());
        $this->assertEquals('view-dashboard', $span->getDescription());
        $this->assertEquals('view-dashboard', $span->getData()['gate.ability']);
        $this->assertEquals('allowed', $span->getData()['gate.result']);
        $this->assertEquals(SpanStatus::ok(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testSpanIsRecordedForDeniedCheck(): void
    {
        Gate::define('view-dashboard', static function ($user = null) {
            return false;
        });

        $transaction = $this->startTransaction();

        $this->assertTrue(Gate::denies('view-dashboard'));

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals('denied', $span->getData()['gate.result']);
        $this->assertEquals(SpanStatus::permissionDenied(), $span->getStatus());
    }

    public function testSpanIsMarkedPermissionDeniedForDenyResponse(): void
    {
        Gate::define('manage-billing', static function ($user = null) {
            return Response::deny('Billing is restricted.');
        });

        $transaction = $this->startTransaction();

        $this->assertTrue(Gate::inspect('manage-billing')->denied());

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals('denied', $span->getData()['gate.result']);
        $this->assertEquals(SpanStatus::permissionDenied(), $span->getStatus());
    }

    public function testSpanIsMarkedPermissionDeniedWhenAuthorizeFails(): void
    {
        Gate::define('view-dashboard', static function ($user = null) {
            return false;
        });

        $transaction = $this->startTransaction();

        try {
            Gate::authorize('view-dashboard');

            $this->fail('Expected the authorization to fail.');
        } catch (AuthorizationException $exception) {
            // Expected
        }

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals('denied', $span->getData()['gate.result']);
        $this->assertEquals(SpanStatus::permissionDenied(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testSpanIsRecordedForPolicyCheck(): void
    {
        Gate::policy(GateIntegrationTestModel::class, GateIntegrationTestPolicy::class);

        $model = (new GateIntegrationTestModel)->forceFill(['id' => 5]);

        $transaction = $this->startTransaction();

        $this->assertTrue(Gate::forUser(new User)->allows('update', $model));

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals('update ' . GateIntegrationTestModel::class, $span->getDescription());
        $this->assertEquals('update', $span->getData()['gate.ability']);
        $this->assertEquals([GateIntegrationTestModel::class . '(5)'], $span->getData()['gate.arguments']);
    }

    public function testSpanIsRecordedForPolicyCheckWithClassName(): void
    {
        Gate::policy(GateIntegrationTestModel::class, GateIntegrationTestPolicy::class);

        $transaction = $this->startTransaction();

        $this->assertTrue(Gate::forUser(new User)->allows('create', GateIntegrationTestModel::class));

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals('create ' . GateIntegrationTestModel::class, $span->getDescription());
        $this->assertEquals([GateIntegrationTestModel::class], $span->getData()['gate.arguments']);
    }

    public function testScalarArgumentsAreRecordedByType(): void
    {
        Gate::define('edit-settings', static function ($user = null, $section = null, $limit = null) {
            return true;
        });

        $transaction = $this->startTransaction();

        $this->assertTrue(Gate::allows('edit-settings', ['billing', 10]));

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals('edit-settings', $span->getDescription());
        $this->assertEquals(['string', 'integer'], $span->getData()['gate.arguments']);
    }

    public function testModelArgumentsWithoutScalarKeyAreRecordedWithoutKey(): void
    {
        Gate::define('compare', static function ($user = null, $first = null, $second = null) {
            return true;
        });

        $transaction = $this->startTransaction();

        // The first model is not persisted and has a `null` key, the second one has a composite key
        $this->assertTrue(Gate::allows('compare', [new GateIntegrationTestModel, new GateIntegrationTestCompositeKeyModel]));

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals('compare ' . GateIntegrationTestModel::class, $span->getDescription());
        $this->assertEquals([
            GateIntegrationTestModel::class,
            GateIntegrationTestCompositeKeyModel::class,
        ], $span->getData()['gate.arguments']);
    }

    public function testSpanIsRecordedForEachAbilityCheckedByAny(): void
    {
        Gate::define('first', static function ($user = null) {
            return false;
        });

        Gate::define('second', static function ($user = null) {
            return true;
        });

        $transaction = $this->startTransaction();

        $this->assertTrue(Gate::any(['first', 'second']));

        [$first, $second] = $this->getGateSpans($transaction);

        $this->assertEquals('first', $first->getDescription());
        $this->assertEquals('denied', $first->getData()['gate.result']);
        $this->assertEquals('second', $second->getDescription());
        $this->assertEquals('allowed', $second->getData()['gate.result']);
        $this->assertEquals($transaction->getSpanId(), $second->getParentSpanId());
    }

    public function testSpanIsRecordedForUserCan(): void
    {
        Gate::define('view-dashboard', static function ($user) {
            return true;
        });

        $transaction = $this->startTransaction();

        $this->assertTrue((new User)->can('view-dashboard'));

        $this->assertCount(1, $this->getGateSpans($transaction));
    }

    public function testNestedChecksAreRecordedAsChildSpans(): void
    {
        Gate::define('inner', static function ($user = null) {
            return true;
        });

        Gate::define('outer', static function ($user = null) {
            return Gate::allows('inner');
        });

        $transaction = $this->startTransaction();

        $this->assertTrue(Gate::allows('outer'));

        [$outer, $inner] = $this->getGateSpans($transaction);

        $this->assertEquals('outer', $outer->getDescription());
        $this->assertEquals('inner', $inner->getDescription());
        $this->assertEquals($outer->getSpanId(), $inner->getParentSpanId());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testSpanIsFinishedAndParentRestoredWhenCheckThrows(): void
    {
        Gate::define('broken', static function ($user = null) {
            throw new RuntimeException('Something went wrong');
        });

        $transaction = $this->startTransaction();

        try {
            Gate::allows('broken');

            $this->fail('Expected the gate check to throw.');
        } catch (RuntimeException $exception) {
            // Expected
        }

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame($transaction, $this->getSentryHubFromContainer()->getSpan());
    }

    public function testSpanIsMarkedPermissionDeniedWhenCheckThrowsAuthorizationException(): void
    {
        Gate::define('forbidden', static function ($user = null) {
            throw new AuthorizationException('Nope');
        });

        $transaction = $this->startTransaction();

        // `inspect()` converts the authorization exception thrown from `raw()` into a deny response
        $this->assertTrue(Gate::inspect('forbidden')->denied());

        $span = $this->getGateSpans($transaction)[0];

        $this->assertEquals('denied', $span->getData()['gate.result']);
        $this->assertEquals(SpanStatus::permissionDenied(), $span->getStatus());
    }

    public function testSpanIsNotRecordedWithoutSampledParent(): void
    {
        Gate::define('view-dashboard', static function ($user = null) {
            return true;
        });

        $this->assertTrue(Gate::allows('view-dashboard'));

        $this->assertNull($this->getSentryHubFromContainer()->getSpan());
    }

    /** @return array<int, Span> */
    private function getGateSpans(\Sentry\Tracing\Transaction $transaction): array
    {
        return array_values(array_filter($transaction->getSpanRecorder()->getSpans(), static function (Span $span) {
            return $span->getOp() === 'gate.check';
        }));
    }
}

class GateIntegrationTestModel extends Model
{
}

class GateIntegrationTestCompositeKeyModel extends Model
{
    public function getKey()
    {
        return ['team_id' => 1, 'user_id' => 2];
    }
}

class GateIntegrationTestPolicy
{
    public function create($user): bool
    {
        return true;
    }

    public function update($user, GateIntegrationTestModel $model): bool
    {
        return true;
    }
}

class GateIntegrationTestCustomGate extends BaseGate
{
}
