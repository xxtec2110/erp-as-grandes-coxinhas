@extends('layouts.app')
@section('title', 'Histórico de estoque')
@section('content')
    <div class="page-header"><div><h1 class="page-title">{{ $product->name }}</h1><p class="page-subtitle">Estoque em {{ $location->name }}</p></div><div class="flex flex-wrap gap-2"><a class="btn-secondary" href="{{ route('stock.index') }}">Voltar</a><a class="btn-primary" href="{{ route('stock.adjustments.create', [$product, $location]) }}">Registrar ajuste</a></div></div>
    <div class="metric-card mb-6"><p class="metric-label">Saldo oficial</p><p class="metric-value text-3xl">{{ \App\Support\DecimalFormatter::format($balance, $product->stock_unit === 'un' ? 0 : 3) }} {{ $product->stock_unit }}</p><p class="mt-2 text-sm text-stone-500">Soma de todos os movimentos registrados até hoje.</p></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Data da operação</th><th>Tipo</th><th>Quantidade</th><th>Responsável</th><th>Observação</th></tr></thead><tbody>
        @forelse ($movements as $movement)<tr><td>{{ $movement->operation_date->format('d/m/Y') }}</td><td>{{ $movement->type->label() }}</td><td class="font-semibold {{ str_starts_with($movement->quantity_delta, '-') ? 'text-red-700' : 'text-emerald-700' }}">{{ str_starts_with($movement->quantity_delta, '-') ? '' : '+' }}{{ \App\Support\DecimalFormatter::format($movement->quantity_delta, $product->stock_unit === 'un' ? 0 : 3) }} {{ $product->stock_unit }}</td><td>{{ $movement->creator?->name ?? 'Sistema' }}</td><td>{{ $movement->notes }}</td></tr>
        @empty <tr><td colspan="5" class="empty-state">Nenhum movimento para este produto nesta unidade.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $movements->links() }}</div>
@endsection
