@extends('layouts.app')
@section('title', 'Categorias de produtos')
@section('content')
<div class="page-header"><div><h1 class="page-title">Categorias de produtos</h1><p class="page-subtitle">Organize o catálogo comercial sem misturar categorias de insumos.</p></div></div>
<div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
    <form method="POST" action="{{ route('product-categories.store') }}" class="form-card h-fit">@csrf
        <h2 class="section-title">Nova categoria</h2>
        <label class="form-label mt-4" for="name">Nome</label><input class="form-input" id="name" name="name" required maxlength="255" value="{{ old('name') }}">
        <label class="mt-4 flex gap-3 text-sm"><input type="checkbox" name="active" value="1" checked> Ativa</label>
        <button class="btn-primary mt-5">Cadastrar</button>
    </form>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Categoria</th><th>Produtos</th><th>Status</th><th>Ação</th></tr></thead><tbody>
    @forelse($categories as $category)<tr><form method="POST" action="{{ route('product-categories.update', $category) }}">@csrf @method('PUT')<td><input class="form-input" name="name" value="{{ $category->name }}" required></td><td>{{ $category->products_count }}</td><td><label class="flex gap-2"><input type="checkbox" name="active" value="1" @checked($category->active)> Ativa</label></td><td><button class="text-link">Salvar</button></td></form></tr>@empty<tr><td colspan="4" class="empty-state">Nenhuma categoria cadastrada.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
