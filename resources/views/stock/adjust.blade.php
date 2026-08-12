@extends('layouts.app')
@section('title', 'Ajustar estoque')
@section('content')
    <div class="mb-6"><h1 class="page-title">Registrar ajuste de estoque</h1><p class="page-subtitle">{{ $product->name }} · {{ $location->name }}</p></div>
    <div class="metric-card mb-6"><p class="metric-label">Saldo atual</p><p class="metric-value text-2xl">{{ \App\Support\DecimalFormatter::format($balance, $product->stock_unit === 'un' ? 0 : 3) }} {{ $product->stock_unit }}</p></div>
    <form method="POST" action="{{ route('stock.adjustments.store', [$product, $location]) }}" class="form-card">@csrf
        @if ($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        <input type="hidden" name="movement_type" value="{{ old('movement_type', $movementType->value) }}">
        <div class="grid gap-5 sm:grid-cols-2">
            <div><label class="form-label" for="direction">Operação</label><select id="direction" name="direction" class="form-input" required><option value="increase" @selected(old('direction') === 'increase')>Adicionar ao estoque</option><option value="decrease" @selected(old('direction') === 'decrease')>Retirar do estoque</option></select></div>
            <div><label class="form-label" for="quantity">Quantidade ({{ $product->stock_unit }})</label><input id="quantity" name="quantity" type="number" min="0.000001" step="0.000001" class="form-input" required value="{{ old('quantity') }}"></div>
            <div><label class="form-label" for="operation_date">Data real da operação</label><input id="operation_date" name="operation_date" type="date" class="form-input" required value="{{ old('operation_date', now()->toDateString()) }}"></div>
            <div class="sm:col-span-2"><label class="form-label" for="notes">Motivo do ajuste</label><textarea id="notes" name="notes" rows="4" class="form-input" maxlength="2000" required>{{ old('notes') }}</textarea><p class="mt-2 text-xs text-stone-500">O histórico não pode ser editado ou apagado. Se necessário, registre um movimento compensatório.</p></div>
        </div>
        <div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Registrar movimento</button><a class="btn-secondary" href="{{ route('stock.show', [$product, $location]) }}">Cancelar</a></div>
    </form>
@endsection
