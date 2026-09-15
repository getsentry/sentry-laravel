<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;

class DatabaseDataCollectionTest extends TestCase
{
    public function testConfiguredDefaultsCollectFilteredBindingsForBreadcrumbsAndSpans(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.breadcrumbs.sql_bindings' => true,
            'sentry.tracing.sql_bindings' => true,
        ]);
        $transaction = $this->startTransaction();
        $unsupported = new \stdClass();
        $bindings = [
            0 => null,
            2 => false,
            7 => 0,
            9 => '',
            ':name' => 'Alice',
            'password' => 'secret',
            'profile' => ['api_token' => 'secret', 'enabled' => true],
            'unsupported' => $unsupported,
        ];

        $this->dispatchQuery($bindings);

        $expected = [
            'db.query.parameter.0' => null,
            'db.query.parameter.2' => false,
            'db.query.parameter.7' => 0,
            'db.query.parameter.9' => '',
            'db.query.parameter.:name' => 'Alice',
            'db.query.parameter.password' => '[Filtered]',
            'db.query.parameter.profile' => ['api_token' => '[Filtered]', 'enabled' => true],
            'db.query.parameter.unsupported' => '[Filtered]',
        ];
        $breadcrumbData = $this->databaseData($this->getLastSentryBreadcrumb()->getMetadata());
        $spanData = $this->databaseData($this->lastSpan($transaction)->getData());

        $this->assertSame($expected, $breadcrumbData);
        $this->assertSame($expected, $spanData);
        $this->assertArrayNotHasKey('bindings', $this->getLastSentryBreadcrumb()->getMetadata());
        $this->assertArrayNotHasKey('db.sql.bindings', $this->lastSpan($transaction)->getData());
        $this->assertSame('secret', $bindings['password']);

        $bindings['profile']['enabled'] = false;
        $this->assertTrue($breadcrumbData['db.query.parameter.profile']['enabled']);
        $this->assertTrue($spanData['db.query.parameter.profile']['enabled']);
    }

    /**
     * @dataProvider bindingPolicyProvider
     *
     * @param array<string, mixed>|null $dataCollection
     */
    public function testBindingPolicyReplacesLegacyFlags(?array $dataCollection, bool $legacyBindings, bool $expectLegacy, bool $expectCollected): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => $dataCollection,
            'sentry.breadcrumbs.sql_bindings' => $legacyBindings,
            'sentry.tracing.sql_bindings' => $legacyBindings,
        ]);
        $transaction = $this->startTransaction();

        $this->dispatchQuery(['name' => 'Alice']);

        $breadcrumbData = $this->getLastSentryBreadcrumb()->getMetadata();
        $spanData = $this->lastSpan($transaction)->getData();
        $this->assertSame($expectLegacy, array_key_exists('bindings', $breadcrumbData));
        $this->assertSame($expectLegacy, array_key_exists('db.sql.bindings', $spanData));
        $this->assertSame($expectCollected, array_key_exists('db.query.parameter.name', $breadcrumbData));
        $this->assertSame($expectCollected, array_key_exists('db.query.parameter.name', $spanData));
    }

    public static function bindingPolicyProvider(): iterable
    {
        yield 'legacy disabled' => [null, false, false, false];
        yield 'legacy enabled' => [null, true, true, false];
        yield 'configured default' => [[], false, false, true];
        yield 'configured disabled overrides legacy' => [['database_query_data' => false], true, false, false];
    }

    public function testEmptyBindingsAddNoQueryParameterAttributes(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        $transaction = $this->startTransaction();

        $this->dispatchQuery([]);

        $this->assertSame([], $this->databaseData($this->getLastSentryBreadcrumb()->getMetadata()));
        $this->assertSame([], $this->databaseData($this->lastSpan($transaction)->getData()));
    }

    public function testRuntimePolicyChangesAffectSubsequentQueries(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        $transaction = $this->startTransaction();

        $this->dispatchQuery(['first' => 'one']);
        $this->getSentryClientFromContainer()->getOptions()->getDataCollection()->setDatabaseQueryData(false);
        $this->dispatchQuery(['second' => 'two']);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertSame('one', $spans[1]->getData()['db.query.parameter.first']);
        $this->assertArrayNotHasKey('db.query.parameter.second', $spans[2]->getData());
        $this->assertArrayNotHasKey('db.query.parameter.second', $this->getLastSentryBreadcrumb()->getMetadata());
    }

    public function testBreadcrumbCollectionWorksWithoutTransactionAndTracingRemainsSamplingAware(): void
    {
        $this->resetApplicationWithConfig(['sentry.data_collection' => []]);
        $this->dispatchQuery(['name' => 'Alice']);
        $this->assertSame('Alice', $this->getLastSentryBreadcrumb()->getMetadata()['db.query.parameter.name']);

        $transaction = $this->startTransaction();
        $transaction->setSampled(false);
        $this->dispatchQuery(['name' => 'Bob']);
        $this->assertCount(1, $transaction->getSpanRecorder()->getSpans());
    }

    public function testDisabledQueryInstrumentationDoesNotCollectBindings(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'sentry.breadcrumbs.sql_queries' => false,
            'sentry.tracing.sql_queries' => false,
        ]);
        $transaction = $this->startTransaction();

        $this->dispatchQuery(['name' => 'Alice']);

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
        $this->assertCount(1, $transaction->getSpanRecorder()->getSpans());
    }

    public function testRealEloquentQueryReturnsResultsAndCollectsBindings(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
            'database.default' => 'eloquent-data-collection',
            'database.connections.eloquent-data-collection' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ]);
        Schema::create('database_data_collection_models', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        DatabaseDataCollectionModel::create(['name' => 'Alice']);
        $transaction = $this->startTransaction();

        $model = DatabaseDataCollectionModel::where('name', 'Alice')->first();

        $this->assertInstanceOf(DatabaseDataCollectionModel::class, $model);
        $this->assertSame('Alice', $model->name);
        $this->assertSame('Alice', $this->getLastSentryBreadcrumb()->getMetadata()['db.query.parameter.0']);
        $this->assertSame('Alice', $this->lastSpan($transaction)->getData()['db.query.parameter.0']);
    }

    /** @param array<array-key, mixed> $bindings */
    private function dispatchQuery(array $bindings): void
    {
        $this->dispatchLaravelEvent(new QueryExecuted('SELECT * FROM users WHERE value = ?', $bindings, 10, DB::connection()));
    }

    /** @return array<string, mixed> */
    private function databaseData(array $data): array
    {
        return array_filter($data, static function (string $key): bool {
            return strpos($key, 'db.query.parameter.') === 0;
        }, \ARRAY_FILTER_USE_KEY);
    }

    private function lastSpan(\Sentry\Tracing\Transaction $transaction): Span
    {
        $spans = $transaction->getSpanRecorder()->getSpans();

        return $spans[count($spans) - 1];
    }
}

class DatabaseDataCollectionModel extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
