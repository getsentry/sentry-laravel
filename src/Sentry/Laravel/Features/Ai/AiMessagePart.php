<?php

namespace Sentry\Laravel\Features\Ai;

class AiMessagePart implements \JsonSerializable
{

    /**
     * @var string
     */
    private $type;

    /**
     * @var string
     */
    private $id = null;

    /**
     * @var string
     */
    private $name = null;

    /**
     * @var string
     */
    private $content = null;

    /**
     * @var string
     */
    private $arguments = null;

    /**
     * @var string
     */
    private $modality = null;

    /**
     * @var string
     */
    private $mimeType = null;
    
    public function __construct(string $type)
    {
        $this->type = $type;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }
    
    public function setArguments(?string $arguments): self
    {
        $this->arguments = $arguments;
        return $this;
    }

    public function getArguments(): ?string
    {
        return $this->arguments;
    }

    public function setModality(?string $modality): self
    {
        $this->modality = $modality;
        return $this;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function toArray(): array
    {
        $data = ['type' => $this->type];
        if ($this->id !== null) {
            $data['id'] = $this->id;
        }
        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        if ($this->content !== null) {
            $data['content'] = $this->content;
        }
        if ($this->arguments !== null) {
            $data['arguments'] = $this->arguments;
        }
        if ($this->modality !== null) {
            $data['modality'] = $this->modality;
        }
        if ($this->mimeType !== null) {
            $data['mime_type'] = $this->mimeType;
        }
        return $data;
    }
    
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
