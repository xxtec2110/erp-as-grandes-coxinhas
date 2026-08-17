<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

class PurchaseDocumentIdentityService
{
    /** @param array<string, mixed> $data */
    public function hash(array $data): ?string
    {
        if (filled($data['access_key'] ?? null)) {
            $accessKey = preg_replace('/\D+/', '', (string) $data['access_key']);
            if ($accessKey !== '') {
                return hash('sha256', 'access-key|'.$accessKey);
            }
        }
        if (! isset($data['supplier_id']) || ! filled($data['document_number'] ?? null) || ! filled($data['issue_date'] ?? null) || ! isset($data['total_amount'])) {
            return null;
        }

        $date = Carbon::parse($data['issue_date'])->toDateString();
        $total = BigDecimal::of(str_replace(',', '.', (string) $data['total_amount']))->toScale(2, RoundingMode::HalfUp);

        return hash('sha256', implode('|', [
            (int) $data['supplier_id'],
            mb_strtoupper(trim((string) $data['document_number'])),
            mb_strtoupper(trim((string) ($data['series'] ?? ''))),
            $date,
            (string) $total,
        ]));
    }
}
