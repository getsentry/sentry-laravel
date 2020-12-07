<?php

namespace Sentry\Laravel;

use Exception;
use Illuminate\Console\Command;
use Sentry\ClientBuilder;
use Sentry\State\Hub;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;

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
    protected $signature = 'sentry:test {--transaction} {--dsn=}';

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
            /** @var \Sentry\State\Hub $hub */
            $hub = app('sentry');

            if ($this->option('dsn')) {
                $hub = new Hub(ClientBuilder::create(['dsn' => $this->option('dsn')])->getClient());
            }

            if ($hub->getClient()->getOptions()->getDsn()) {
                $this->info('[Sentry] DSN discovered!');
            } else {
                $this->error('[Sentry] Could not discover DSN!');
                $this->error('[Sentry] Please check if your DSN is set properly in your config or `.env` as `SENTRY_LARAVEL_DSN`.');

                return;
            }

            if ($this->option('transaction')) {
                $hub->getClient()->getOptions()->setTracesSampleRate(1);
            }

            $transactionContext = new TransactionContext();
            $transactionContext->setName('Sentry Test Transaction');
            $transactionContext->setOp('sentry.test');
            $transaction = $hub->startTransaction($transactionContext);

            $spanContext = new SpanContext();
            $spanContext->setOp('sentry.sent');
            $span1 = $transaction->startChild($spanContext);

            $this->info('[Sentry] Generating test Event');

            $ex = $this->generateTestException('command name', ['foo' => 'bar']);

            $eventId = $hub->captureException($ex);

            $this->info('[Sentry] Sending test Event');

            $span1->finish();
            $result = $transaction->finish();
            if ($result) {
                $this->info("[Sentry] Transaction sent: {$result}");
            }

            if (!$eventId) {
                $this->error('[Sentry] There was an error sending the test event.');
                $this->error('[Sentry] Please check if your DSN is set properly in your config or `.env` as `SENTRY_LARAVEL_DSN`.');
            } else {
                $this->info("[Sentry] Event sent with ID: {$eventId}");
            }
        } catch (Exception $e) {
            $this->error("[Sentry] {$e->getMessage()}");
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
    protected function generateTestException($command, $arg): ?Exception
    {
        // Do something silly
        try {
            throw new Exception('This is a test exception sent from the Sentry Laravel SDK.');
        } catch (Exception $ex) {
            return $ex;
        }
    }
}
