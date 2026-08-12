@extends('layouts.app')
@section('title', 'GLP / Energia')
@section('content')
    <div class="page-header"><div><h1 class="page-title">GLP / Energia</h1><p class="page-subtitle">Recipientes, cargas e custo atual do GLP por kg.</p></div><a class="btn-primary" href="{{ route('glp-products.create') }}">Novo recipiente</a></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Recipiente</th><th>Peso líquido</th><th>Preço atual</th><th>Custo por kg</th><th>Data</th><th>Status</th><th class="text-right">Ações</th></tr></thead><tbody>
        @forelse ($products as $product)<tr><td class="font-semibold">{{ $product->name }}</td><td>{{ \App\Support\DecimalFormatter::format($product->net_weight_kg, 3) }} kg</td><td>@if ($product->currentPrice)R$ {{ \App\Support\DecimalFormatter::format($product->currentPrice->total_price) }}@else — @endif</td><td>@if ($product->currentPrice)R$ {{ \App\Support\DecimalFormatter::format($product->currentPrice->unit_cost_per_kg, 4) }}@else Sem preço @endif</td><td>{{ $product->currentPrice?->effective_date?->format('d/m/Y') ?? '—' }}</td><td><span class="status-badge {{ $product->active ? 'status-active' : 'status-inactive' }}">{{ $product->active ? 'Ativo' : 'Inativo' }}</span></td><td class="whitespace-nowrap text-right"><a class="text-link" href="{{ route('glp-products.show', $product) }}">Abrir</a><a class="ml-3 text-link" href="{{ route('glp-products.edit', $product) }}">Editar</a></td></tr>
        @empty <tr><td colspan="7" class="empty-state">Nenhum recipiente de GLP cadastrado.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $products->links() }}</div>
@endsection
