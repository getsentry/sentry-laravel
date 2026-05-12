<?php

namespace Sentry\Laravel\Features\Ai;

class AiMessage implements \JsonSerializable
{

    /**
     * @var string
     */
    private $role;

    /**
     * @var AiMessagePart[]
     */
    private $parts;

    /**
     * @param AiMessagePart[] $parts
     */
    public function __construct(string $role, array $parts = [])
    {
        $this->role = $role;
        $this->parts = $parts;
    }

    /**
     * @return AiMessagePart[]
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    public function jsonSerialize()
    {
        return [
            'role' => $this->role,
            'parts' => $this->parts,
        ];
    }
}
