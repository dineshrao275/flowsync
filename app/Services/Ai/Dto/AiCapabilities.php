<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/** What a provider + model can do. Features ask for a capability; a missing one is an explicit error, never a silent downgrade. */
final readonly class AiCapabilities
{
    public const FEATURES = ['text', 'streaming', 'tools', 'parallel_tools', 'json_schema_output', 'structured_with_tools', 'documents'];

    public function __construct(
        public bool $text = true,
        public bool $streaming = false,
        public bool $tools = false,
        public bool $parallelTools = false,
        public bool $jsonSchemaOutput = false,
        public bool $structuredWithTools = false,
        public bool $documents = false,
        public int $contextWindow = 0,
    ) {}

    /** @param array<string,mixed> $config keys use snake_case as in config/ai.php */
    public static function fromArray(array $config): self
    {
        return new self(
            text: (bool) ($config['text'] ?? true),
            streaming: (bool) ($config['streaming'] ?? false),
            tools: (bool) ($config['tools'] ?? false),
            parallelTools: (bool) ($config['parallel_tools'] ?? false),
            jsonSchemaOutput: (bool) ($config['json_schema_output'] ?? false),
            structuredWithTools: (bool) ($config['structured_with_tools'] ?? false),
            documents: (bool) ($config['documents'] ?? false),
            contextWindow: (int) ($config['context_window'] ?? 0),
        );
    }

    public function supports(string $feature): bool
    {
        return match ($feature) {
            'text' => $this->text,
            'streaming' => $this->streaming,
            'tools' => $this->tools,
            'parallel_tools' => $this->parallelTools,
            'json_schema_output' => $this->jsonSchemaOutput,
            'structured_with_tools' => $this->structuredWithTools,
            'documents' => $this->documents,
            default => false,
        };
    }

    /** @return array<string,bool|int> */
    public function toArray(): array
    {
        return [
            'text' => $this->text, 'streaming' => $this->streaming, 'tools' => $this->tools,
            'parallel_tools' => $this->parallelTools, 'json_schema_output' => $this->jsonSchemaOutput,
            'structured_with_tools' => $this->structuredWithTools, 'documents' => $this->documents,
            'context_window' => $this->contextWindow,
        ];
    }
}
