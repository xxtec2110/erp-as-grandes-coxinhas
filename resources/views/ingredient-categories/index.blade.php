@extends('layouts.app')
@section('title', 'Categorias de insumos')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Categorias de insumos</h1><p class="page-subtitle">Organize os insumos sem alterar seus preços ou unidades.</p></div><a class="btn-primary" href="{{ route('ingredient-categories.create') }}">Nova categoria</a></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Categoria</th><th>Observações</th><th>Insumos</th><th>Status</th><th class="text-right">Ação</th></tr></thead><tbody>
        @forelse ($categories as $category)<tr><td class="font-semibold">{{ $category->name }}</td><td>{{ $category->notes ?: '—' }}</td><td>{{ $category->ingredients_count }}</td><td><span class="status-badge {{ $category->active ? 'status-active' : 'status-inactive' }}">{{ $category->active ? 'Ativa' : 'Inativa' }}</span></td><td class="text-right"><a class="text-link" href="{{ route('ingredient-categories.edit', $category) }}">Editar</a></td></tr>
        @empty <tr><td class="empty-state" colspan="5">Nenhuma categoria cadastrada.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $categories->links() }}</div>
@endsection
