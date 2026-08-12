@extends('layouts.app')
@section('title', 'Registrar venda')
@section('content')
<div class="page-header"><div><h1 class="page-title">Registrar venda</h1><p class="page-subtitle">A baixa será lançada no estoque oficial com rastreabilidade.</p></div><a class="btn-secondary" href="{{ route('sales.index') }}">Voltar</a></div>
<form method="POST" action="{{ route('sales.store') }}" class="form-card max-w-3xl">@csrf
@if($errors->any())<div class="mb-5 rounded-xl bg-red-50 p-4 text-sm text-red-800">{{ $errors->first() }}</div>@endif
<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
<div class="grid gap-5 sm:grid-cols-2">
<label><span class="form-label">Unidade</span><select class="form-input" name="location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(old('location_id')==$location->id)>{{ $location->name }}</option>@endforeach</select></label>
<label><span class="form-label">Data da operação</span><input class="form-input" type="date" name="operation_date" value="{{ old('operation_date', now()->toDateString()) }}" required></label>
<label class="sm:col-span-2"><span class="form-label">Produto</span><select class="form-input" name="product_id" required><option value="">Selecione</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('product_id')==$product->id)>{{ $product->name }} · {{ $product->category?->name ?? 'Sem categoria' }} ({{ $product->stock_unit }})</option>@endforeach</select></label>
<label><span class="form-label">Quantidade</span><input class="form-input" name="quantity" inputmode="decimal" value="{{ old('quantity') }}" required></label>
<label><span class="form-label">Preço unitário (R$)</span><input class="form-input" name="unit_price" inputmode="decimal" value="{{ old('unit_price') }}" required></label>
<label><span class="form-label">Forma de pagamento</span><select class="form-input" name="payment_method" required><option value="cash">Dinheiro / sem taxa</option><option value="debit">Débito</option><option value="credit">Crédito</option></select></label>
<label><span class="form-label">Adquirente</span><select class="form-input" name="acquirer_id"><option value="">Não se aplica</option>@foreach($acquirers as $acquirer)<option value="{{ $acquirer->id }}">{{ $acquirer->name }}</option>@endforeach</select></label>
<label><span class="form-label">Bandeira</span><select class="form-input" name="card_brand_id"><option value="">Não se aplica</option>@foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select></label>
<label><span class="form-label">Parcelas</span><input class="form-input" type="number" min="1" max="99" name="installments" value="{{ old('installments') }}" placeholder="Opcional"></label>
<label class="sm:col-span-2"><span class="form-label">Observações</span><textarea class="form-input" name="notes" rows="3">{{ old('notes') }}</textarea></label>
</div><button class="btn-primary mt-6">Registrar venda</button></form>
@endsection
