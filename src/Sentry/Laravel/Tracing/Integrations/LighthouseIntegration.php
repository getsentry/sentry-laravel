<?php

namespace Sentry\Laravel\Tracing\Integrations;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Nuwave\Lighthouse\Events\EndExecution;
use Nuwave\Lighthouse\Events\EndRequest;
use Nuwave\Lighthouse\Events\StartExecution;
use Nuwave\Lighthouse\Events\StartRequest;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

class LighthouseIntegration implements IntegrationInterface
{
    /** @var array<int, array{?string, \GraphQL\Language\AST\OperationDefinitionNode}> $operations */
    private $operations;

    /** @var \Sentry\Tracing\Span|null $previousSpan */
    private $previousSpan;

    /** @var \Sentry\Tracing\Span|null $requestSpan */
    private $requestSpan;

    /** @var \Sentry\Tracing\Span|null $operationSpan */
    private $operationSpan;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        $eventDispatcher->listen(StartRequest::class, [$this, 'handleStartRequest']);
        $eventDispatcher->listen(StartExecution::class, [$this, 'handleStartExecution']);
        $eventDispatcher->listen(EndExecution::class, [$this, 'handleEndExecution']);
        $eventDispatcher->listen(EndRequest::class, [$this, 'handleEndRequest']);
    }

    public function handleStartRequest(StartRequest $startRequest): void
    {
        $this->previousSpan = Integration::currentTracingSpan();

        if ($this->previousSpan === null) {
            return;
        }

        $context = new SpanContext;
        $context->setOp('graphql.request');

        $this->operations    = [];
        $this->requestSpan   = $this->previousSpan->startChild($context);
        $this->operationSpan = null;

        SentrySdk::getCurrentHub()->setSpan($this->requestSpan);
    }

    public function handleStartExecution(StartExecution $startExecution): void
    {
        if ($this->requestSpan === null) {
            return;
        }

        if (!$startExecution->query instanceof DocumentNode) {
            return;
        }

        /** @var \GraphQL\Language\AST\OperationDefinitionNode|null $operationDefinition */
        $operationDefinition = $startExecution->query->definitions[0] ?? null;

        if (!$operationDefinition instanceof OperationDefinitionNode) {
            return;
        }

        $this->operations[] = [$startExecution->operationName ?? null, $operationDefinition];

        $context = new SpanContext;
        $context->setOp(
            sprintf(
                'graphql.%s{%s}',
                $operationDefinition->operation,
                $startExecution->operationName ?? implode(',', $this->extractOperationNames($operationDefinition))
            )
        );

        $this->operationSpan = $this->requestSpan->startChild($context);

        SentrySdk::getCurrentHub()->setSpan($this->operationSpan);
    }

    public function handleEndExecution(EndExecution $endExecution): void
    {
        if ($this->operationSpan === null) {
            return;
        }

        $this->operationSpan->finish();
        $this->operationSpan = null;

        SentrySdk::getCurrentHub()->setSpan($this->requestSpan);
    }

    public function handleEndRequest(EndRequest $endRequest): void
    {
        if ($this->requestSpan === null) {
            return;
        }

        $this->requestSpan->finish();
        $this->requestSpan = null;

        SentrySdk::getCurrentHub()->setSpan($this->previousSpan);
        $this->previousSpan = null;

        $this->updateTransaction();

        $this->operations = [];
    }

    private function updateTransaction(): void
    {
        $transaction = SentrySdk::getCurrentHub()->getTransaction();

        if ($transaction === null) {
            return;
        }

        $groupedOperations = [];

        foreach ($this->operations as [$operationName, $operation]) {
            if (!isset($groupedOperations[$operation->operation])) {
                $groupedOperations[$operation->operation] = [];
            }

            if ($operationName === null) {
                $groupedOperations[$operation->operation] = array_merge(
                    $groupedOperations[$operation->operation],
                    $this->extractOperationNames($operation)
                );
            } else {
                $groupedOperations[$operation->operation][] = $operationName;
            }
        }

        array_walk($groupedOperations, static function (array &$operations, string $operationType) {
            sort($operations, SORT_STRING);

            $operations = "{$operationType}{" . implode(',', $operations) . '}';
        });

        ksort($groupedOperations, SORT_STRING);

        $transactionName = 'lighthouse?' . implode('&', $groupedOperations);

        $transaction->setName($transactionName);
    }

    /**
     * @return array<int, string>
     */
    private function extractOperationNames(OperationDefinitionNode $operation): array
    {
        if ($operation->name !== null) {
            return [$operation->name->value];
        }

        $selectionSet = [];

        /** @var \GraphQL\Language\AST\FieldNode $selection */
        foreach ($operation->selectionSet->selections as $selection) {
            // Not respecting aliases because they are only relevant for clients
            // and the tracing we extract here is targeted at server developers.
            $selectionSet[] = $selection->name->value;
        }

        sort($selectionSet, SORT_STRING);

        return $selectionSet;
    }

    public static function supported(): bool
    {
        if (!class_exists(StartRequest::class) || !class_exists(StartExecution::class)) {
            return false;
        }

        return property_exists(StartExecution::class, 'query');
    }
}