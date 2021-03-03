<?php

namespace Sentry\Laravel\Integration;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\State\Scope;
use Sentry\Integration\IntegrationInterface;

class ExceptionContextIntegration implements IntegrationInterface
{
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(static function (Event $event, ?EventHint $hint = null): Event {
            if ($hint === null || $hint->exception === null) {
                return $event;
            }

            if (!method_exists($hint->exception, 'context')) {
                return $event;
            }

            $context = $hint->exception->context();

            if (is_array($context)) {
                $event->setExtra(['exception_context' => $context]);
            }

            return $event;
        });
    }
}
