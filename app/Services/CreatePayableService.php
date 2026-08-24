<?php

namespace App\Services;

use App\Models\Payable;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Validator;

class CreatePayableService
{
    public function __construct(private AuthorizationService $auth, private FinanceAuditService $audit) {}

    public function create(array $data, User $user, string $source = 'web'): Payable
    {
        $data['recurring'] ??= false;
        $validator = Validator::make($data, [
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'description' => ['required', 'string', 'max:255'],
            'purchase_document_id' => ['nullable', 'exists:purchase_documents,id'],
            'location_id' => ['required', 'exists:locations,id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'finance_category_id' => ['nullable', 'exists:finance_categories,id'],
            'expected_amount' => ['required', 'decimal:0,2', 'gt:0'],
            'competency_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'recurring' => ['required', 'boolean'],
            'recurrence_rule' => ['nullable', 'required_if:recurring,true', 'in:weekly,biweekly,monthly,annual'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:150'],
        ]);
        if ($validator->fails()) {
            throw new DomainException($validator->errors()->first());
        }
        $data = $validator->validated();
        $this->auth->authorize($user, 'finance.payables.create', (int) $data['location_id']);
        $existing = Payable::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return $existing;
        }
        $payable = Payable::query()->create([...$data, 'source' => $source, 'created_by' => $user->id, 'status' => 'pending']);
        $this->audit->record('payable.created', $payable, $user, $payable->toArray(), null, $source, $data['idempotency_key']);

        return $payable;
    }
}
