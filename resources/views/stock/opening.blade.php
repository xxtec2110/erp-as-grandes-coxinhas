@extends('layouts.app')
@section('title', 'Estoque inicial')
@section('content')
    <div class="page-header">
        <div><h1 class="page-title">Estoque inicial</h1><p class="page-subtitle">Informe o saldo real contado. Nada será gravado antes da confirmação da prévia.</p></div>
        <a class="btn-secondary" href="{{ route('stock.index') }}">Voltar ao estoque</a>
    </div>

    <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
        <strong>Operação única e auditável.</strong> O saldo oficial continuará sendo a soma dos movimentos. Depois do primeiro movimento do produto na unidade, correções devem ser feitas por ajuste, nunca por um segundo estoque inicial.
    </div>

    <form method="POST" action="{{ route('stock.opening.preview') }}" class="form-card max-w-3xl space-y-6">
        @csrf
        @if ($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        <div class="grid gap-5 sm:grid-cols-2">
            <div><label class="form-label" for="product_id">Produto</label><select class="form-input" id="product_id" name="product_id" required><option value="">Selecione</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) old('product_id', request('product_id')) === (string) $product->id)>{{ $product->name }} ({{ $product->stock_unit }})</option>@endforeach</select></div>
            <div><label class="form-label" for="location_id">Unidade/localização</label><select class="form-input" id="location_id" name="location_id" required><option value="">Selecione</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) old('location_id', request('location_id')) === (string) $location->id)>{{ $location->name }}</option>@endforeach</select></div>
            <div><label class="form-label" for="quantity">Quantidade real contada</label><input class="form-input" id="quantity" name="quantity" type="number" min="0.000001" step="0.000001" required value="{{ old('quantity') }}"></div>
            <div><label class="form-label" for="operation_date">Data real da contagem</label><input class="form-input" id="operation_date" name="operation_date" type="date" required value="{{ old('operation_date') }}"><p class="mt-2 text-xs text-stone-500">Não é preenchida automaticamente: confirme a data real da operação.</p></div>
            <div class="sm:col-span-2"><label class="form-label" for="notes">Justificativa/origem da contagem</label><textarea class="form-input" id="notes" name="notes" rows="4" maxlength="2000" required>{{ old('notes') }}</textarea></div>
        </div>
        <button class="btn-primary" type="submit">Gerar prévia</button>
    </form>
@endsection
