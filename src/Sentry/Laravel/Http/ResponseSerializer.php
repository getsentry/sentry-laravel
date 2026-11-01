<?php

namespace Sentry\Laravel\Http;

use Illuminate\Http\Client\Response;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpBodyCollector;
use Sentry\DataCollection\HttpHeaderCollector;
use Sentry\DataCollection\HttpMessageType;
use Sentry\SentrySdk;

class ResponseSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Response $response): array
    {
        $data = [
            'status' => $response->status(),
        ];

        $policy = DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub());
        $type = HttpMessageType::incomingResponse();

        $headers = HttpHeaderCollector::collect($policy, $type, $response->headers());

        if ($headers !== null) {
            $data['headers'] = $headers;
        }

        $body = HttpBodyCollector::collectPsr7Message(
            $policy,
            $type,
            $response->toPsrResponse()
        );

        if ($body !== null) {
            $data['body'] = $body;
        }

        return $data;
    }
}
