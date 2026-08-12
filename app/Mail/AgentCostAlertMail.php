<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AgentCostAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $level, public string $estimatedTotal) {}

    public function build(): self
    {
        return $this->subject('Custo do Agente: '.strtoupper($this->level))->view('emails.agent-cost-alert');
    }
}
