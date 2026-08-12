<?php

namespace App\Services;

use App\Enums\ProductionStatus;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\ProductionRecord;
use App\Models\User;
use DomainException;

class UndoLastOperationService
{
    public function __construct(
        private AuthorizationService $authorization,
        private CancelPaymentService $payments,
        private CancelPayableService $payables,
        private ProductionService $production,
    ) {}

    /** @return array{operation_type: string, operation_id: int, location_id: int, summary: string} */
    public function candidate(User $user): array
    {
        $cutoff = now()->subDays(7);
        $candidates = collect();
        if ($this->authorization->allows($user, 'finance.payments.cancel')) {
            Payment::query()->with('payable.supplier')->where('created_by', $user->id)->where('status', 'completed')->where('created_at', '>=', $cutoff)->latest()->limit(2)->get()->each(fn ($payment) => $candidates->push(['operation_type' => 'payment', 'operation_id' => $payment->id, 'location_id' => $payment->payable->location_id, 'summary' => 'Pagamento '.($payment->payable->supplier?->name ?? $payment->payable->description).' — R$ '.$payment->amount, 'created_at' => $payment->created_at]));
        }
        if ($this->authorization->allows($user, 'finance.payables.cancel')) {
            Payable::query()->with('supplier')->where('created_by', $user->id)->whereNotIn('status', ['cancelled', 'paid'])->where('created_at', '>=', $cutoff)->latest()->limit(2)->get()->each(fn ($payable) => $candidates->push(['operation_type' => 'payable', 'operation_id' => $payable->id, 'location_id' => $payable->location_id, 'summary' => 'Conta '.($payable->supplier?->name ?? $payable->description).' — R$ '.$payable->expected_amount, 'created_at' => $payable->created_at]));
        }
        if ($this->authorization->allows($user, 'production.cancel')) {
            ProductionRecord::query()->with('product')->where('created_by', $user->id)->where('status', ProductionStatus::Planned)->where('created_at', '>=', $cutoff)->latest()->limit(2)->get()->each(fn ($record) => $candidates->push(['operation_type' => 'production', 'operation_id' => $record->id, 'location_id' => $record->location_id, 'summary' => 'Produção planejada '.$record->product->name.' — '.$record->planned_quantity.' un', 'created_at' => $record->created_at]));
        }
        $candidates = $candidates->filter(fn ($item) => $this->authorization->accessibleLocations($user)->contains('id', $item['location_id']))->sortByDesc('created_at')->values();
        if ($candidates->isEmpty()) {
            throw new DomainException('Nenhuma operação recente, reversível e autorizada foi encontrada.');
        }
        if ($candidates->count() > 1 && $candidates[0]['created_at']->diffInMinutes($candidates[1]['created_at']) < 2) {
            throw new DomainException("Encontrei mais de uma operação recente. Abra o painel para escolher com segurança:\n".$candidates->take(5)->pluck('summary')->map(fn ($text, $index) => ($index + 1).'. '.$text)->implode("\n"));
        }

        return collect($candidates->first())->except('created_at')->all();
    }

    public function undo(array $input, User $user): object
    {
        return match ($input['operation_type']) {
            'payment' => $this->payments->cancel(Payment::query()->findOrFail($input['operation_id']), $user, $input['reason'] ?? 'Cancelamento confirmado pelo agente.', 'agent'),
            'payable' => $this->payables->cancel(Payable::query()->findOrFail($input['operation_id']), $user),
            'production' => $this->cancelProduction($input, $user),
            default => throw new DomainException('Tipo de operação não reversível.'),
        };
    }

    private function cancelProduction(array $input, User $user): ProductionRecord
    {
        $record = ProductionRecord::query()->findOrFail($input['operation_id']);
        $this->authorization->authorize($user, 'production.cancel', $record->location_id);

        return $this->production->cancel($record);
    }
}
