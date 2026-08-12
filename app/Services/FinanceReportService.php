<?php

namespace App\Services;

use App\Models\Payable;
use App\Models\Payment;

class FinanceReportService
{
    public function summary(array $locationIds, string $start, string $end): array
    {
        $payables = Payable::query()->whereIn('location_id', $locationIds)->whereBetween('competency_date', [$start, $end]);
        $payments = Payment::query()->completed()->whereHas('payable', fn ($q) => $q->whereIn('location_id', $locationIds))->whereBetween('paid_at', [$start.' 00:00:00', $end.' 23:59:59']);

        return ['expected' => (string) (clone $payables)->sum('expected_amount'), 'open' => (string) (clone $payables)->whereNotIn('status', ['paid', 'cancelled'])->sum('expected_amount'), 'paid' => (string) (clone $payments)->sum('amount'), 'overdue' => (string) (clone $payables)->whereDate('due_date', '<', now())->whereNotIn('status', ['paid', 'cancelled'])->sum('expected_amount'), 'by_account' => (clone $payments)->join('financial_accounts', 'financial_accounts.id', '=', 'payments.financial_account_id')->selectRaw('financial_accounts.name, SUM(payments.amount) total')->groupBy('financial_accounts.id', 'financial_accounts.name')->get(), 'by_payer' => (clone $payments)->selectRaw("COALESCE(paid_by_name, 'Não informado') name, SUM(amount) total")->groupBy('paid_by_name')->get()];
    }
}
