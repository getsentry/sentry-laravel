<?php

namespace Sentry\Laravel\Console;

use Sentry\Client;
use Sentry\Laravel\Version;
use Sentry\State\HubInterface;

class AboutCommandIntegration
{
    public function __invoke(HubInterface $hub): array
    {
        $client = $hub->getClient();

        if ($client === null) {
            return [
                'Enabled' => '<fg=red;options=bold>NO</>',
                'PHP SDK Version' => Client::SDK_VERSION,
                'Laravel SDK Version' => Version::SDK_VERSION,
            ];
        }

        $options = $client->getOptions();

        $profilesSampleRate = $options->getProfilesSampleRate() ?? '<fg=yellow;options=bold>NOT SET</>';

        $tracesSampleRate = $options->getTracesSampleRate() ?? '<fg=yellow;options=bold>NOT SET</>';

        if ($options->getTracesSampler() !== null) {
            $tracesSampleRate = '<fg=green;options=bold>CUSTOM SAMPLER</>';
        }

        return [
            'Enabled' => $options->getDsn() ? '<fg=green;options=bold>YES</>' : '<fg=red;options=bold>NO, MISSING DSN</>',
            'Environment' => $options->getEnvironment() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Release' => $options->getRelease() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Sample Rate' => $options->getSampleRate(),
            'Sample Rate Profiling' => $profilesSampleRate,
            'Sample Rate Performance Monitoring' => $tracesSampleRate,
            'Send Default PII' => $options->shouldSendDefaultPii() ? '<fg=yellow;options=bold>ENABLED</>' : '<fg=green;options=bold>DISABLED</>',
            'PHP SDK Version' => Client::SDK_VERSION,
            'Laravel SDK Version' => Version::SDK_VERSION,
        ];
    }
}
