<?php

namespace App\WhatsApp;

class FakeWhatsAppMediaDownloader implements WhatsAppMediaDownloaderInterface
{
    /** @var array<string, DownloadedMedia> */
    private array $media = [];

    public function add(DownloadedMedia $media): void
    {
        $this->media[$media->mediaId] = $media;
    }

    public function download(string $mediaId): DownloadedMedia
    {
        return $this->media[$mediaId] ?? throw new MediaDownloadException('media_not_found');
    }
}
