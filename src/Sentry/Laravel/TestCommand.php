<?php

namespace Sentry\Laravel;

use Exception;
use Illuminate\Console\Command;
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
    protected $signature = 'sentry:test {--transaction}';

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

            if ($hub->getClient()->getOptions()->getDsn()) {
                $this->info('[Sentry] DSN discovered!');
            } else {
                $this->error('[Sentry] Could not discover DSN!');
                $this->error('[Sentry] Please check if you DSN is set properly in your config or `.env` as `SENTRY_LARAVEL_DSN`.');

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

            $hub->captureException($ex);

            $this->info('[Sentry] Sending test Event');

            $span1->finish();
            $result = $transaction->finish();
            if ($result) {
                $this->info('[Sentry] Sending test Transaction');
            }

            $lastEventId = $hub->getLastEventId();

            if (!$lastEventId) {
                $this->error('[Sentry] There was an error sending the test event.');
                $this->error('[Sentry] Please check if you DSN is set properly in your config or `.env` as `SENTRY_LARAVEL_DSN`.');
            } else {
                $this->info("[Sentry] Event sent with ID: {$lastEventId}");
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
