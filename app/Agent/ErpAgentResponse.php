<?php

namespace App\Agent;

readonly class ErpAgentResponse
{
    public function __construct(public bool $success, public string $message, public string $responseType = 'text', public array $data = [], public array $options = [], public ?array $pendingAction = null, public ?string $errorCode = null, public array $metadata = []) {}

    public static function error(string $message, string $code, string $type = 'error'): self
    {
        return new self(false, $message, $type, errorCode: $code);
    }

    public function toArray(): array
    {
        return ['success' => $this->success, 'message' => $this->message, 'response_type' => $this->responseType, 'data' => $this->data, 'options' => $this->options, 'pending_action' => $this->pendingAction, 'error_code' => $this->errorCode, 'metadata' => $this->metadata];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['success'], $data['message'], $data['response_type'] ?? 'text', $data['data'] ?? [], $data['options'] ?? [], $data['pending_action'] ?? null, $data['error_code'] ?? null, $data['metadata'] ?? []);
    }
}
