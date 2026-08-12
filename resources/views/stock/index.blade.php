@extends('layouts.app')
@section('title', 'Estoque')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Estoque oficial</h1><p class="page-subtitle">Saldos calculados pelo histórico de movimentos de cada produto e unidade.</p></div><a class="btn-secondary" href="{{ route('stock-policies.index') }}">Políticas de estoque</a></div>

    <section class="form-card mb-6">
        <h2 class="mb-4 text-lg font-bold">Consultar produto e unidade</h2>
        <form method="GET" action="{{ route('stock.index') }}" class="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
            <div><label class="form-label" for="product_id">Produto</label><select class="form-input" id="product_id" name="product_id" required><option value="">Selecione</option>@foreach ($products as $product)<option value="{{ $product->id }}" @selected($selectedProduct?->id === $product->id)>{{ $product->name }}</option>@endforeach</select></div>
            <div><label class="form-label" for="location_id">Unidade</label><select class="form-input" id="location_id" name="location_id" required><option value="">Selecione</option>@foreach ($locations as $location)<option value="{{ $location->id }}" @selected($selectedLocation?->id === $location->id)>{{ $location->name }}</option>@endforeach</select></div>
            <button class="btn-primary" type="submit">Consultar</button>
        </form>
        @if ($selectedProduct && $selectedLocation)
            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:flex sm:items-center sm:justify-between">
                <div><p class="text-sm text-stone-600">{{ $selectedProduct->name }} · {{ $selectedLocation->name }}</p><p class="mt-1 text-2xl font-bold">{{ \App\Support\DecimalFormatter::format($balance, $selectedProduct->stock_unit === 'un' ? 0 : 3) }} {{ $selectedProduct->stock_unit }}</p></div>
                <div class="mt-3 flex gap-2 sm:mt-0"><a class="btn-secondary" href="{{ route('stock.show', [$selectedProduct, $selectedLocation]) }}">Ver histórico</a><a class="btn-primary" href="{{ route('stock.adjustments.create', [$selectedProduct, $selectedLocation]) }}">Registrar ajuste</a></div>
            </div>
        @elseif (request()->hasAny(['product_id', 'location_id']))
            <p class="mt-4 text-sm font-medium text-red-700">Selecione um produto e uma unidade ativos.</p>
        @endif
    </section>

    @if($selectedLocation)
        <section class="mb-6"><h2 class="mb-4 text-lg font-bold">Posição em {{ $selectedLocation->name }}</h2><div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Produto</th><th>Atual</th><th>Mínimo</th><th>Alvo</th><th>Necessidade</th><th>Situação</th></tr></thead><tbody>@forelse($stockPositions as $row)<tr><td class="font-semibold">{{ $row['product']->name }}</td><td>{{ \App\Support\DecimalFormatter::format($row['balance'], $row['product']->stock_unit === 'un' ? 0 : 3) }} {{ $row['product']->stock_unit }}</td><td>{{ $row['minimum'] !== null ? \App\Support\DecimalFormatter::format($row['minimum'], $row['product']->stock_unit === 'un' ? 0 : 3) : '—' }}</td><td>{{ $row['target'] !== null ? \App\Support\DecimalFormatter::format($row['target'], $row['product']->stock_unit === 'un' ? 0 : 3) : '—' }}</td><td>{{ \App\Support\DecimalFormatter::format($row['requirement'], $row['product']->stock_unit === 'un' ? 0 : 3) }}</td><td>{{ $row['situation']->label() }}</td></tr>@empty<tr><td colspan="6" class="empty-state">Nenhum produto cadastrado.</td></tr>@endforelse</tbody></table></div></div></section>
    @endif

    <section><h2 class="mb-4 text-lg font-bold">Movimentos recentes</h2><div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Data</th><th>Produto</th><th>Unidade</th><th>Tipo</th><th>Quantidade</th></tr></thead><tbody>
        @forelse ($recentMovements as $movement)<tr><td>{{ $movement->operation_date->format('d/m/Y') }}</td><td>{{ $movement->product->name }}</td><td>{{ $movement->location->name }}</td><td>{{ $movement->type->label() }}</td><td class="font-semibold {{ str_starts_with($movement->quantity_delta, '-') ? 'text-red-700' : 'text-emerald-700' }}">{{ str_starts_with($movement->quantity_delta, '-') ? '' : '+' }}{{ \App\Support\DecimalFormatter::format($movement->quantity_delta, $movement->product->stock_unit === 'un' ? 0 : 3) }} {{ $movement->product->stock_unit }}</td></tr>
        @empty <tr><td colspan="5" class="empty-state">Nenhum movimento registrado.</td></tr> @endforelse
    </tbody></table></div></div></section>
@endsection
