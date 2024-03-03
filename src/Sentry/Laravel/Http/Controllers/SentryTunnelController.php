<?php

namespace Sentry\Laravel\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SentryTunnelController extends Controller
{
    /**
     * Sentry Data Source Name.
     *
     * @var string|null
     */
    protected $dsn;

    /**
     * Handle the incoming Sentry request.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function __invoke(Request $request)
    {
        $this->dsn = config('sentry.dsn');

        if (!$this->shouldReport()) {
            return response(null, 204);
        }

        $envelope = $request->getContent();
        $headers = array_map(
            function ($line) {
                return json_decode($line, true);
            },
            preg_split('/\r\n|\r|\n/', $envelope)
        )[0];

        if (empty($headers['dsn']) || $headers['dsn'] != $this->dsn) {
            return response()->json(null, 401);
        }

        $parsed = parse_url($this->dsn);
        $url = sprintf(
            'https://%s.ingest.sentry.io/api/%d/envelope/',
            explode('.', $parsed['host'])[0],
            last(explode('/', rtrim($parsed['path'], '/')))
        );

        // HTTP Client not available in Laravel 6
        //$response = Http::withBody($envelope, 'application/x-sentry-envelope')->post($url);
        $response = (new Client())->post($url, [
            'headers' => [ 'Content-Type' => 'application/x-sentry-envelope'],
            'body' => $envelope
        ]);

        return response()->json($response->getBody(), $response->getStatusCode());
    }

    /**
     * Determine if the request should be reported to Sentry.
     */
    public function shouldReport(): bool
    {
        return !empty($this->dsn);
    }
}
