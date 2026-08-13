<?php

namespace App\Agent;

readonly class AudioTranscription
{
    public function __construct(public string $text, public ?string $duration = null, public array $usage = []) {}
}
