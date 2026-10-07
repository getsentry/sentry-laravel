<?php

namespace Sentry\Laravel\Console;

use Sentry\Client;
use Sentry\Laravel\Version;
use Sentry\Options;
use Sentry\State\HubInterface;

class AboutCommandIntegration
{
    public function __invoke(HubInterface $hub): array
    {
        $client = $hub->getClient();

        if ($client === null) {
            return [
                'Enabled' => '<fg=red;options=bold>NOT CONFIGURED</>',
                'Laravel SDK Version' => Version::SDK_VERSION,
                'PHP SDK Version' => Client::SDK_VERSION,
            ];
        }

        $options = $client->getOptions();

        // Note: order is not important since Laravel orders these alphabetically
        return [
            'Data Collection' => $options->getDataCollection() !== null ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>NOT SET</>',
            'Enabled' => $options->getDsn() ? '<fg=green;options=bold>YES</>' : '<fg=red;options=bold>MISSING DSN</>',
            'Environment' => $options->getEnvironment() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Laravel SDK Version' => Version::SDK_VERSION,
            'PHP SDK Version' => Client::SDK_VERSION,
            'Release' => $options->getRelease() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Sample Rate Errors' => $this->formatSampleRate($options->getSampleRate()),
            'Sample Rate Performance Monitoring' => $this->formatSampleRate($options->getTracesSampleRate(), $options->getTracesSampler() !== null),
            'Sample Rate Profiling' => $this->formatSampleRate($options->getProfilesSampleRate()),
            'Send Default PII' => $this->formatSendDefaultPii($options),
        ];
    }

    private function formatSendDefaultPii(Options $options): string
    {
        // The data collection options replace `send_default_pii`
        if ($options->getDataCollection() !== null) {
            return '<fg=yellow;options=bold>IGNORED</>';
        }

        return $options->shouldSendDefaultPii() ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>DISABLED</>';
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
