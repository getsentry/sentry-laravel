<?php

namespace Sentry\Laravel\Tests\Features;

use ReflectionMethod;
use Sentry\Laravel\Features\LivewirePackageIntegration;
use Sentry\Laravel\Integration;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\TransactionSource;

class LivewirePackageIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Integration::setTransaction(null);

        parent::tearDown();
    }

    public function testLivewireTransactionNameIsOnlySetOncePerTransaction(): void
    {
        $transaction = $this->startTransaction();
        $transaction->setName('/livewire/update');

        $integration = new LivewirePackageIntegration($this->app);

        $this->updateTransactionName($integration, 'main');

        $this->assertSame('livewire?component=main', $transaction->getName());
        $this->assertSame(TransactionSource::custom(), $transaction->getMetadata()->getSource());
        $this->assertSame('livewire?component=main', Integration::getTransaction());

        $this->updateTransactionName($integration, 'child');

        $this->assertSame('livewire?component=main', $transaction->getName());
        $this->assertSame(TransactionSource::custom(), $transaction->getMetadata()->getSource());
        $this->assertSame('livewire?component=main', Integration::getTransaction());
    }

    private function updateTransactionName(LivewirePackageIntegration $integration, string $componentName): void
    {
        $method = new ReflectionMethod($integration, 'updateTransactionName');

        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $method->invoke($integration, $componentName);
    }
}
