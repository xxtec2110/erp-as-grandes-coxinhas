<?php

namespace App\WhatsApp;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsAppFailureClassifier
{
    public function isTransient(Throwable $exception): bool
    {
        return ! ($exception instanceof DomainException
            || $exception instanceof AuthorizationException
            || $exception instanceof ValidationException);
    }
}
