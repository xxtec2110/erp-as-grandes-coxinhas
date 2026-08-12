@extends('layouts.app')
@section('title', 'Planejar produção')
@section('content')
    <div class="mb-6"><h1 class="page-title">Planejar produção</h1><p class="page-subtitle">O planejamento não altera o estoque. O saldo aumenta somente após a conclusão.</p></div>
    <form method="POST" action="{{ route('production.store') }}" class="form-card">@csrf
        @if ($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        <div class="grid gap-5 sm:grid-cols-2">
            <div><label class="form-label" for="product_id">Produto</label><select id="product_id" name="product_id" class="form-input" required><option value="">Selecione</option>@foreach ($products as $product)<option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>{{ $product->name }} ({{ $product->stock_unit }})</option>@endforeach</select></div>
            <div><label class="form-label" for="location_id">Unidade de produção</label><select id="location_id" name="location_id" class="form-input" required><option value="">Selecione</option>@foreach ($locations as $location)<option value="{{ $location->id }}" @selected((string) old('location_id') === (string) $location->id)>{{ $location->name }}</option>@endforeach</select></div>
            <div><label class="form-label" for="planned_quantity">Quantidade planejada</label><input id="planned_quantity" name="planned_quantity" type="number" min="0.000001" step="0.000001" class="form-input" required value="{{ old('planned_quantity') }}"></div>
            <div><label class="form-label" for="operation_date">Data real da produção</label><input id="operation_date" name="operation_date" type="date" class="form-input" required value="{{ old('operation_date', now()->toDateString()) }}"></div>
            <div class="sm:col-span-2"><label class="form-label" for="notes">Observações</label><textarea id="notes" name="notes" rows="4" class="form-input">{{ old('notes') }}</textarea></div>
        </div>
        <div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Salvar planejamento</button><a class="btn-secondary" href="{{ route('production.index') }}">Cancelar</a></div>
    </form>
@endsection
