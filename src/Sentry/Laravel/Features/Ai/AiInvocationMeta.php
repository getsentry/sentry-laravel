<?php

namespace Sentry\Laravel\Features\Ai;

/**
 * @internal
 */
class AiInvocationMeta
{
    /** @var string */
    public $agentName;

    /** @var string|null */
    public $providerName;

    /** @var string|null */
    public $model;

    /** @var string */
    public $prompt;

    /** @var AiMessagePart[] */
    public $attachments;

    /** @var string|null */
    public $toolDefinitions;

    public function __construct(string $agentName, ?string $providerName, ?string $model, string $prompt, array $attachments, ?string $toolDefinitions)
    {
        $this->agentName = $agentName;
        $this->providerName = $providerName;
        $this->model = $model;
        $this->prompt = $prompt;
        $this->attachments = $attachments;
        $this->toolDefinitions = $toolDefinitions;
    }
}
