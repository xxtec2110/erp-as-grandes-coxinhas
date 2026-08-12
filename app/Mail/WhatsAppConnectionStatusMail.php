<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WhatsAppConnectionStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $connectionStatus, public ?string $reason = null) {}

    public function build(): self
    {
        return $this->subject('WhatsApp do Agente: '.$this->connectionStatus)
            ->view('emails.whatsapp-connection-status');
    }
}
