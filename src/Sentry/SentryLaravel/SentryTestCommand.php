<?php

namespace Sentry\SentryLaravel;

use Illuminate\Console\Command;

class SentryTestCommand extends Command
{
    // XXX(dcramer): Laravel 4.x compatibility
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
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    // XXX(dcramer): Laravel 4.x compatibility
    public function fire()
    {
        $this->handle();
    }

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
            $client = app('sentry');

            $config = get_object_vars($client);
            $required_keys = array('server', 'project', 'public_key', 'secret_key');

            $output = "";
            foreach ($required_keys as $key) {
                if (empty($config[$key])) {
                    $this->error("[sentry] ERROR: Missing configuration for $key");
                }
                if (is_array($config[$key])) {
                    $output .= "-> $key: [".implode(", ", $config[$key])."]\n";
                } else {
                    $output .= "-> $key: $config[$key]\n";
                }
            }

            $this->info("[sentry] Client configuration:\n" . trim($output));

            $this->info('[sentry] Generating test event');

            $ex = $this->generateTestException("command name", array("foo" => "bar"));

            $event_id = $client->captureException($ex);

            $this->info("[sentry] Sending test event with ID: $event_id");

            $last_error = $client->getLastError();
            if (!empty($last_error)) {
                $this->error("[sentry] There was an error sending the test event:\n $last_error");
            }
        } finally {
            error_reporting($old_error_reporting);
        }
    }

    protected function generateTestException($command, $arg)
    {
        // Do something silly
        try {
            throw new \Exception('This is a test exception sent from the Raven CLI.');
        } catch (\Exception $ex) {
            return $ex;
        }
    }
}
