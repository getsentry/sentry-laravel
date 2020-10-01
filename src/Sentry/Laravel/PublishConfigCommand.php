<?php

namespace Sentry\Laravel;

use Illuminate\Console\Command;
use Sentry\Dsn;

class PublishConfigCommand extends Command
{
    /**
     * Laravel 5.0.x: The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'sentry:publish';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentry:publish {--dsn=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publishes the Sentry Config';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('[Sentry] Publishing config ...');
        $this->call('vendor:publish', [
            '--provider' => 'Sentry\Laravel\ServiceProvider'
        ]);

        $args = [];

        $dsn = $this->option('dsn');

        if (!$this->isKeySet('SENTRY_LARAVEL_DSN')) {
            while (empty($dsn)) {
                $this->info('');
                $this->question('[Sentry] Please paste the DSN here');
                $dsn = $this->ask('DSN');
                // In case someone copies it with SENTRY_LARAVEL_DSN=
                $dsn = str_replace('SENTRY_LARAVEL_DSN=', '', $dsn);
                try {
                    $dsnObj = Dsn::createFromString($dsn);
                } catch (\Exception $e) {
                    // Not a valid DSN do it again
                    $this->error('[Sentry] The DSN is not valid, please make sure to paste a valid DSN');
                    $dsn = '';
                    continue;
                }
            };

            $this->setEnvironmentValue(['SENTRY_LARAVEL_DSN' => $dsn]);
            $args = array_merge($args, ['--dsn' => $dsn]);
        }

        if ($this->confirm('Enable Performance Monitoring?', true)) {
            $this->setEnvironmentValue(['SENTRY_TRACES_SAMPLE_RATE' => 1.0]);

            $this->info('[Sentry] Added `SENTRY_TRACES_SAMPLE_RATE=1` to your .env file.');

            $testCommandPrompt = 'Want to send a test Event & Transaction?';
            $args = array_merge($args, ['--transaction' => true]);
        } else {
            $testCommandPrompt = 'Want to send a test Event?';
        }

        if ($this->confirm($testCommandPrompt, true)) {
            $this->call('sentry:test', $args);
        }
    }

    public function isKeySet(string $key)
    {
        $envFile = app()->environmentFilePath();
        return strpos(file_get_contents($envFile), $key) !== false;
    }

    public function setEnvironmentValue(array $values)
    {
        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);

        if (count($values) > 0) {
            foreach ($values as $envKey => $envValue) {
                $str .= "\n"; // In case the searched variable is in the last line without \n
                $keyPosition = strpos($str, "{$envKey}=");
                $endOfLinePosition = strpos($str, "\n", $keyPosition);
                $oldLine = substr($str, $keyPosition, $endOfLinePosition - $keyPosition);

                // If key does not exist, add it
                if (!$keyPosition || !$endOfLinePosition || !$oldLine) {
                    $str .= "{$envKey}={$envValue}\n";
                } else {
                    $str = str_replace($oldLine, "{$envKey}={$envValue}", $str);
                }
            }
        }

        $str = substr($str, 0, -1);
        if (!file_put_contents($envFile, $str)) {
            return false;
        }
        return true;
    }
}
