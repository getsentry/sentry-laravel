<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Http;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;

class HttpClientDataCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ResponseReceived::class)) {
            $this->markTestSkipped('The Laravel HTTP client events are only available in Laravel 8.0+');
        }

        parent::setUp();
    }

    public function testSpanUrlHeadersAndCookiesAreFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $data = $this->executeRequestAndReturnSpan()->getData();

        $this->assertSame('https://example.com/path', $data['url']);
        $this->assertSame('https://[Filtered]:[Filtered]@example.com/path?token=[Filtered]&page=5', $data['url.full']);
        $this->assertSame('token=[Filtered]&page=5', $data['http.query']);

        $this->assertSame(['[Filtered]'], $data['http.request.header.authorization']);
        $this->assertSame(['bar'], $data['http.request.header.x-request-id']);
        $this->assertArrayNotHasKey('http.request.header.cookie', $data);
        $this->assertSame('dark', $data['http.request.header.cookie.theme']);
        $this->assertSame('[Filtered]', $data['http.request.header.cookie.session_id']);

        $this->assertSame(['baz'], $data['http.response.header.x-request-id']);
        $this->assertSame(['[Filtered]'], $data['http.response.header.x-api-key']);
        $this->assertArrayNotHasKey('http.response.header.set-cookie', $data);
        $this->assertSame('light', $data['http.response.header.set_cookie.theme']);
        $this->assertSame('[Filtered]', $data['http.response.header.set_cookie.session_id']);
    }

    public function testBreadcrumbUrlIsFiltered(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [],
        ]);

        $this->executeRequest();

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertSame('https://example.com/path', $metadata['url']);
        $this->assertSame('https://[Filtered]:[Filtered]@example.com/path?token=[Filtered]&page=5', $metadata['url.full']);
        $this->assertSame('token=[Filtered]&page=5', $metadata['http.query']);
        $this->assertSame([], $this->getHeaderKeys($metadata));
    }

    public function testSpanDataIsNotCollectedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'cookies' => ['mode' => 'off'],
                'http_headers' => ['mode' => 'off'],
                'url_query_params' => ['mode' => 'off'],
            ],
        ]);

        $data = $this->executeRequestAndReturnSpan()->getData();

        $this->assertSame('https://[Filtered]:[Filtered]@example.com/path', $data['url.full']);
        $this->assertArrayNotHasKey('http.query', $data);
        $this->assertSame([], $this->getHeaderKeys($data));
    }

    public function testLegacySpanAndBreadcrumbDataIsNotChanged(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
        ]);

        $data = $this->executeRequestAndReturnSpan()->getData();

        $this->assertSame('https://example.com/path', $data['url']);
        $this->assertSame('token=secret&page=5', $data['http.query']);
        $this->assertArrayNotHasKey('url.full', $data);
        $this->assertSame([], $this->getHeaderKeys($data));

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertSame('token=secret&page=5', $metadata['http.query']);
        $this->assertArrayNotHasKey('url.full', $metadata);
    }

    public function testLegacyDataKeepsAnEmptyQueryString(): void
    {
        $transaction = $this->startTransaction();

        Http::fake()->get('https://example.com/path');

        /** @var Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertSame('', $span->getData()['http.query']);
        $this->assertSame('', $this->getLastSentryBreadcrumb()->getMetadata()['http.query']);
    }

    private function executeRequestAndReturnSpan(): Span
    {
        $transaction = $this->startTransaction();

        $this->executeRequest();

        /** @var Span $span */
        $span = last($transaction->getSpanRecorder()->getSpans());

        $this->assertSame('http.client', $span->getOp());

        return $span;
    }

    private function executeRequest(): void
    {
        $client = Http::fake([
            'example.com/*' => Http::response('', 200, [
                'X-Request-Id' => 'baz',
                'X-Api-Key' => 'secret',
                'Set-Cookie' => ['theme=light; Path=/', 'session_id=foo; HttpOnly'],
            ]),
        ]);

        $client->withHeaders([
            'Authorization' => 'Bearer foo',
            'X-Request-Id' => 'bar',
            'Cookie' => 'theme=dark; session_id=foo',
        ])->get('https://user:pass@example.com/path?token=secret&page=5');
    }

    /**
     * @return string[]
     */
    private function getHeaderKeys(array $data): array
    {
        return array_values(array_filter(array_keys($data), static function (string $key): bool {
            return strpos($key, 'http.request.header.') === 0 || strpos($key, 'http.response.header.') === 0;
        }));
    }
}
