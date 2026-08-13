<?php

namespace App\WhatsApp;

interface WhatsAppMediaDownloaderInterface
{
    public function download(string $mediaId): DownloadedMedia;
}
