@extends('layouts.app')
@section('title', 'Nova transferência')
@section('content')
    <div class="mb-6"><h1 class="page-title">Nova transferência</h1><p class="page-subtitle">O estoque da origem será reduzido apenas na expedição; o destino aumentará somente no recebimento.</p></div>
    <form method="POST" action="{{ route('transfers.store') }}" class="form-card">@csrf
        @if ($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        <div class="grid gap-5 sm:grid-cols-2">
            <div><label class="form-label" for="source_location_id">Unidade de origem</label><select id="source_location_id" name="source_location_id" class="form-input" required><option value="">Selecione</option>@foreach ($locations as $location)<option value="{{ $location->id }}" @selected((string) old('source_location_id') === (string) $location->id)>{{ $location->name }}</option>@endforeach</select></div>
            <div><label class="form-label" for="destination_location_id">Unidade de destino</label><select id="destination_location_id" name="destination_location_id" class="form-input" required><option value="">Selecione</option>@foreach ($locations as $location)<option value="{{ $location->id }}" @selected((string) old('destination_location_id') === (string) $location->id)>{{ $location->name }}</option>@endforeach</select></div>
            <div><label class="form-label" for="product_id">Produto</label><select id="product_id" name="product_id" class="form-input" required><option value="">Selecione</option>@foreach ($products as $product)<option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>{{ $product->name }} ({{ $product->stock_unit }})</option>@endforeach</select></div>
            <div><label class="form-label" for="quantity">Quantidade enviada</label><input id="quantity" name="quantity" type="number" min="0.000001" step="0.000001" class="form-input" required value="{{ old('quantity') }}"></div>
            <div><label class="form-label" for="operation_date">Data do planejamento</label><input id="operation_date" name="operation_date" type="date" class="form-input" required value="{{ old('operation_date', now()->toDateString()) }}"></div>
            <div class="sm:col-span-2"><label class="form-label" for="notes">Observações</label><textarea id="notes" name="notes" rows="4" class="form-input">{{ old('notes') }}</textarea></div>
        </div>
        <div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Criar transferência</button><a class="btn-secondary" href="{{ route('transfers.index') }}">Cancelar</a></div>
    </form>
@endsection
