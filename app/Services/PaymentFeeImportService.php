<?php

namespace App\Services;

use App\Models\PaymentFeeImport;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class PaymentFeeImportService
{
    public function __construct(private AuthorizationService $authorization, private PaymentFeeService $fees) {}

    /** @param array<int, array<string, mixed>> $rows */
    public function preview(array $rows, User $user, string $idempotencyKey, string $source = 'manual_batch', ?string $attachmentId = null): PaymentFeeImport
    {
        $this->authorization->authorize($user, 'payment_fees.import');
        $existing = PaymentFeeImport::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        return PaymentFeeImport::query()->create(['source' => $source, 'attachment_id' => $attachmentId, 'acquirer_id' => $rows[0]['acquirer_id'] ?? null, 'status' => PaymentFeeImport::AWAITING_CONFIRMATION, 'parsed_payload' => ['rows' => $rows], 'idempotency_key' => $idempotencyKey, 'created_by' => $user->id]);
    }

    public function confirm(PaymentFeeImport $import, User $user): PaymentFeeImport
    {
        $this->authorization->authorize($user, 'payment_fees.approve_import');
        if ($import->status === PaymentFeeImport::APPLIED) {
            return $import;
        }
        if ($import->status !== PaymentFeeImport::AWAITING_CONFIRMATION) {
            throw new DomainException('Esta importação não está aguardando confirmação.');
        }

        return DB::transaction(function () use ($import, $user): PaymentFeeImport {
            $locked = PaymentFeeImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($locked->status === PaymentFeeImport::APPLIED) {
                return $locked;
            }
            $locked->update(['status' => PaymentFeeImport::CONFIRMED, 'confirmed_by' => $user->id, 'confirmed_at' => now()]);
            foreach ($locked->parsed_payload['rows'] as $row) {
                $this->fees->apply($row, $user, $locked->source, $locked->id);
            }
            $locked->update(['status' => PaymentFeeImport::APPLIED]);

            return $locked->refresh();
        });
    }

    public function reject(PaymentFeeImport $import, User $user): PaymentFeeImport
    {
        $this->authorization->authorize($user, 'payment_fees.approve_import');
        if ($import->status !== PaymentFeeImport::AWAITING_CONFIRMATION) {
            throw new DomainException('Esta importação não pode mais ser rejeitada.');
        }
        $import->update(['status' => PaymentFeeImport::REJECTED, 'confirmed_by' => $user->id, 'confirmed_at' => now()]);

        return $import;
    }
}
