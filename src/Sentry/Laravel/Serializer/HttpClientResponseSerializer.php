<?php

namespace Sentry\Laravel\Serializer;

use Illuminate\Http\Client\Response;
use Psr\Http\Message\UriInterface;

/**
 * Class serializer for `Illuminate\Http\Client\Response` objects so they show useful
 * information (instead of just the class name) when they appear in stack trace frames.
 *
 * Response headers are only included when `send_default_pii` is enabled. The response
 * body is never included because it can be large, can contain sensitive data and
 * reading it could consume a non-seekable stream.
 *
 * @internal
 */
class HttpClientResponseSerializer
{
    /**
     * @var bool
     */
    private $sendDefaultPii;

    public function __construct(bool $sendDefaultPii = false)
    {
        $this->sendDefaultPii = $sendDefaultPii;
    }

    /**
     * @param Response $response
     *
     * @return array<string, mixed>
     */
    public function __invoke($response): array
    {
        if (!$response instanceof Response) {
            return [];
        }

        $data = [
            'status' => $response->status(),
            // `Response::reason()` only exists since Laravel 8.76, the PSR response works on all supported versions
            'reason' => $response->toPsrResponse()->getReasonPhrase(),
        ];

        $uri = $this->getEffectiveUri($response);

        if ($uri !== null) {
            if (!$this->sendDefaultPii) {
                $uri = $uri->withUserInfo('')->withQuery('')->withFragment('');
            }

            $data['url'] = (string)$uri;
        }

        $contentType = $response->header('Content-Type');

        if ($contentType !== '') {
            $data['content_type'] = $contentType;
        }

        if ($this->sendDefaultPii) {
            $headers = [];

            // Flatten the header values so they are not cut off by the max serialization depth
            foreach ($response->headers() as $name => $values) {
                $headers[$name] = \is_array($values) ? implode(', ', $values) : (string)$values;
            }

            $data['headers'] = $headers;
        }

        return $data;
    }

    private function getEffectiveUri(Response $response): ?UriInterface
    {
        // The transfer stats are only available when the request was actually sent (not for faked or manually constructed responses)
        if (!isset($response->transferStats) || !method_exists($response, 'effectiveUri')) {
            return null;
        }

        try {
            $uri = $response->effectiveUri();
        } catch (\Throwable $e) {
            return null;
        }

        return $uri instanceof UriInterface ? $uri : null;
    }
}
