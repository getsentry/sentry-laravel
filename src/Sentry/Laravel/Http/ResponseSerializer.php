<?php

namespace Sentry\Laravel\Http;

use Illuminate\Http\Client\Response;

class ResponseSerializer
{
    /**
     * Serializes an HTTP client response.
     *
     * @param Response $response The HTTP response
     *
     * @return array<string, mixed>
     */
    public function __invoke(Response $response): array
    {
        return [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
        ];
    }
}
