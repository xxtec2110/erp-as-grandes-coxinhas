<?php

namespace App\WhatsApp;

readonly class DownloadedMedia
{
    public function __construct(public string $mediaId, public string $mimeType, public string $filename, public string $contents) {}
}
