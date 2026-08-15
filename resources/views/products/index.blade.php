@extends('layouts.app')
@section('title', 'Produtos')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Produtos</h1><p class="page-subtitle">Produtos finais que possuem estoque por unidade.</p></div><a class="btn-primary" href="{{ route('products.create') }}">Novo produto</a></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Nome</th><th>Unidade</th><th>Status</th><th class="text-right">Ação</th></tr></thead><tbody>
        @forelse ($products as $product)<tr><td><span class="font-semibold">{{ $product->name }}</span>@if($product->aliases->isNotEmpty())<span class="mt-1 block text-xs text-stone-500">Aliases: {{ $product->aliases->pluck('name')->implode(', ') }}</span>@endif</td><td>{{ $product->stock_unit }}</td><td><span class="status-badge {{ $product->active ? 'status-active' : 'status-inactive' }}">{{ $product->active ? 'Ativo' : 'Inativo' }}</span></td><td class="text-right"><a class="text-link" href="{{ route('products.edit', $product) }}">Editar</a></td></tr>
        @empty <tr><td colspan="4" class="empty-state">Nenhum produto cadastrado.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $products->links() }}</div>
@endsection
