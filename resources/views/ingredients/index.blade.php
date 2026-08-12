@extends('layouts.app')
@section('title', 'Insumos')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Insumos</h1><p class="page-subtitle">Controle o preço atual e o custo normalizado dos ingredientes.</p></div><a class="btn-primary" href="{{ route('ingredients.create') }}">Novo insumo</a></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Insumo</th><th>Categoria</th><th>Marca</th><th>Unidade-base</th><th>Fornecedor atual</th><th>Custo-base</th><th>Status</th><th class="text-right">Ações</th></tr></thead><tbody>
        @forelse ($ingredients as $ingredient)
            <tr>
                <td class="font-semibold">{{ $ingredient->name }}</td>
                <td>{{ $ingredient->category?->name ?? 'Sem categoria' }}</td>
                <td>{{ $ingredient->brand ?: '—' }}</td>
                <td>{{ $ingredient->base_unit }}</td>
                <td>{{ $ingredient->currentPrice?->supplier?->name ?? 'Sem preço' }}</td>
                <td>@if ($ingredient->currentPrice) R$ {{ \App\Support\DecimalFormatter::format($ingredient->currentPrice->base_unit_cost, 4) }} / {{ $ingredient->base_unit }} @else — @endif</td>
                <td><span class="status-badge {{ $ingredient->active ? 'status-active' : 'status-inactive' }}">{{ $ingredient->active ? 'Ativo' : 'Inativo' }}</span></td>
                <td class="text-right whitespace-nowrap"><a class="text-link" href="{{ route('ingredients.show', $ingredient) }}">Abrir</a><a class="ml-3 text-link" href="{{ route('ingredients.edit', $ingredient) }}">Editar</a></td>
            </tr>
        @empty <tr><td colspan="8" class="empty-state">Nenhum insumo cadastrado.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $ingredients->links() }}</div>
@endsection
