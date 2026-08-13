<?php

namespace App\WhatsApp;

class UnavailableWhatsAppMediaDownloader implements WhatsAppMediaDownloaderInterface
{
    public function download(string $mediaId): DownloadedMedia
    {
        throw new MediaDownloadException('media_downloader_unavailable');
    }
}
