<?php

namespace App\Pdv;

enum PdvCapability: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
}
