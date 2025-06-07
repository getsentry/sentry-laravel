<?php

namespace Sentry\Laravel\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Sentry\Dsn;
use Sentry\Laravel\ServiceProvider;

class PublishCommand extends Command
{
    protected $signature = <<<COMMAND
sentry:publish 
    { --dsn= : The DSN to configure }
    { --without-test : Do not send a test event }
    { --with-send-default-pii : Include information such as request headers, IP address and the authenticated user to events collected by the SDK }
    { --without-performance-monitoring : Do not enable performance monitoring }
    { --without-javascript-sdk : Do not enable the JavaScript SDK (deprecated; option unused) }
COMMAND;

    protected $description = 'Publishes and configures the Sentry config.';

    public function handle(): int
    {
        $arg = [];
        $env = [];

        $dsn = $this->option('dsn');

        if (!empty($dsn) || !$this->isEnvKeySet('SENTRY_LARAVEL_DSN')) {
            if (empty($dsn)) {
                $dsnFromInput = $this->askForDsnInput();

                if (empty($dsnFromInput)) {
                    $this->error('Please provide a valid DSN using the `--dsn` option or setting `SENTRY_LARAVEL_DSN` in your `.env` file!');

                    return 1;
                }

                $dsn = $dsnFromInput;
            }

            $env['SENTRY_LARAVEL_DSN'] = $dsn;
            $arg['--dsn']              = $dsn;
        }

        $sendDefaultPii = $this->confirm(
            "Do you want to include information such as request headers, IP address and the authenticated user to events collected by the SDK?\n You can read more about this on https://docs.sentry.io/platforms/php/guides/laravel/data-management/data-collected/",
            $this->option('with-send-default-pii') === true
        );

        if ($sendDefaultPii) {
            $env['SENTRY_SEND_DEFAULT_PII'] = 'true';
        } elseif ($this->isEnvKeySet('SENTRY_SEND_DEFAULT_PII')) {
            $env['SENTRY_SEND_DEFAULT_PII'] = 'false';
        }

        $testCommandPrompt = 'Do you want to send a test event to Sentry?';

        if ($this->confirm('Enable Performance Monitoring?', !$this->option('without-performance-monitoring'))) {
            $testCommandPrompt = 'Do you want to send a test event & transaction to Sentry?';

            $env['SENTRY_TRACES_SAMPLE_RATE'] = '1.0';

            $arg['--transaction'] = true;
        } elseif ($this->isEnvKeySet('SENTRY_TRACES_SAMPLE_RATE')) {
            $env['SENTRY_TRACES_SAMPLE_RATE'] = '0';
        }

        if ($this->confirm($testCommandPrompt, !$this->option('without-test'))) {
            $testResult = $this->call('sentry:test', $arg);

            if ($testResult === 1) {
                return 1;
            }
        }

        $this->info('Publishing Sentry config...');
        $this->call('vendor:publish', ['--provider' => ServiceProvider::class]);

        if (!$this->setEnvValues($env)) {
            return 1;
        }

        return 0;
    }

    private function setEnvValues(array $values): bool
    {
        $envFilePath = app()->environmentFilePath();

        $envFileContents = file_get_contents($envFilePath);

        if (!$envFileContents) {
            $this->error('Could not read `.env` file!');

            return false;
        }

        if (count($values) > 0) {
            foreach ($values as $envKey => $envValue) {
                if ($this->isEnvKeySet($envKey, $envFileContents)) {
                    $envFileContents = preg_replace("/^{$envKey}=\"?.*?\"?(\s|$)/m", "{$envKey}={$envValue}\n", $envFileContents);

                    $this->info("Updated {$envKey} with new value in your `.env` file.");
                } else {
                    // Ensure there is a newline before writing env variables
                    if (substr($envFileContents, -1) !== "\n") {
                        $envFileContents .= "\n";
                    }
                    $envFileContents .= "{$envKey}={$envValue}\n";

                    $this->info("Added {$envKey} to your `.env` file.");
                }
            }
        }

        if (!file_put_contents($envFilePath, $envFileContents)) {
            $this->error('Updating the `.env` file failed!');

            return false;
        }

        return true;
    }

    private function isEnvKeySet(string $envKey, ?string $envFileContents = null): bool
    {
        $envFileContents = $envFileContents ?? file_get_contents(app()->environmentFilePath());

        return (bool)preg_match("/^{$envKey}=\"?.*?\"?(\s|$)/m", $envFileContents);
    }

    private function askForDsnInput(): string
    {
        if ($this->option('no-interaction')) {
            return '';
        }

        while (true) {
            $this->info('');

            $this->question('Please paste the DSN here');

            $dsn = $this->ask('DSN');

            // In case someone copies it with SENTRY_LARAVEL_DSN= or SENTRY_DSN=
            $dsn = Str::after($dsn, '=');

            try {
                Dsn::createFromString($dsn);

                return $dsn;
            } catch (Exception $e) {
                // Not a valid DSN do it again
                $this->error('The DSN is not valid, please make sure to paste a valid DSN!');
            }
        }
    }
}
