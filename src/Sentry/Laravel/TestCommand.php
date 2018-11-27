<?php

namespace Sentry\Laravel;

use Illuminate\Console\Command;

class TestCommand extends Command
{
    /**
     * Laravel 5.0.x: The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'sentry:test';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentry:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a test event and send it to Sentry';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Maximize error reporting
        $old_error_reporting = error_reporting(E_ALL | E_STRICT);

        try {
            $hub = app('sentry');

            $config = get_object_vars($hub);

            $this->info("[sentry] Client configuration:");

            $this->info(var_dump($config));

            $this->info('[sentry] Generating test event');

            $ex = $this->generateTestException('command name', array('foo' => 'bar'));

            $hub->captureException($ex);

            $this->info("[sentry] Sending test event with ID: " . $hub->getLastEventId());

            $last_error = $hub->getLastEventId();
            if (!$last_error) {
                $this->error("[sentry] There was an error sending the test event:\n $last_error");
            }
        } catch (\Exception $e) {
            // Ignore any exceptions
        }

        error_reporting($old_error_reporting);
    }

    /**
     * Generate a test exception to send to Sentry.
     *
     * @param $command
     * @param $arg
     *
     * @return \Exception
     */
    protected function generateTestException($command, $arg)
    {
        // Do something silly
        try {
            throw new \Exception('This is a test exception sent from the Sentry Laravel SDK.');
        } catch (\Exception $ex) {
            return $ex;
        }
    }
}
