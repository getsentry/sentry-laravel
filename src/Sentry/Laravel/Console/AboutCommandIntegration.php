<?php

namespace Sentry\Laravel\Console;

use Sentry\Client;
use Sentry\Laravel\Sentry;
use Sentry\Laravel\Version;
use Sentry\NoOpClient;

class AboutCommandIntegration
{
    public function __invoke(Sentry $sentry): array
    {
        $client = $sentry->getClient();

        if ($client instanceof NoOpClient) {
            return [
                'Enabled' => '<fg=red;options=bold>NOT CONFIGURED</>',
                'Laravel SDK Version' => Version::SDK_VERSION,
                'PHP SDK Version' => Client::SDK_VERSION,
            ];
        }

        $options = $client->getOptions();

        // Note: order is not important since Laravel orders these alphabetically
        return [
            'Enabled' => $options->getDsn() ? '<fg=green;options=bold>YES</>' : '<fg=red;options=bold>MISSING DSN</>',
            'Environment' => $options->getEnvironment() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Laravel SDK Version' => Version::SDK_VERSION,
            'PHP SDK Version' => Client::SDK_VERSION,
            'Release' => $options->getRelease() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Sample Rate Errors' => $this->formatSampleRate($options->getSampleRate()),
            'Sample Rate Performance Monitoring' => $this->formatSampleRate($options->getTracesSampleRate(), $options->getTracesSampler() !== null),
            'Sample Rate Profiling' => $this->formatSampleRate($options->getProfilesSampleRate()),
        ];
    }

    private function formatSampleRate(?float $sampleRate, bool $hasSamplerCallback = false): string
    {
        if ($hasSamplerCallback) {
            return '<fg=green;options=bold>CUSTOM SAMPLER</>';
        }

        if ($sampleRate === null) {
            return '<fg=yellow;options=bold>NOT SET</>';
        }

        return number_format($sampleRate * 100) . '%';
    }
}
