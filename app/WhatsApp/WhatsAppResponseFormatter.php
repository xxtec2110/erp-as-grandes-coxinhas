<?php

namespace App\WhatsApp;

use App\Agent\ErpAgentResponse;

class WhatsAppResponseFormatter
{
    public function text(ErpAgentResponse $response): string
    {
        $text = trim($response->message);
        if ($response->options !== []) {
            $options = collect($response->options)->values()->map(
                fn (array $option, int $index) => ($index + 1).'. '.($option['label'] ?? $option['title'] ?? 'Opção')
            )->implode("\n");
            $text .= "\n\n".$options;
        }

        return $text;
    }
}
