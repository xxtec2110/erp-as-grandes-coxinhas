@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
    <section class="rounded-2xl bg-stone-900 p-6 text-white shadow-sm sm:p-8">
        <p class="text-sm font-medium text-amber-700">Acesso autenticado</p>
        <h1 class="mt-2 text-2xl font-bold sm:text-3xl">Olá, {{ auth()->user()->name }}.</h1>
        <p class="mt-3 max-w-2xl text-stone-300">Esta é a central operacional de As Grandes Coxinhas. Os módulos serão liberados gradualmente sem interromper os cadastros que já funcionam.</p>
        <div class="mt-6 flex flex-wrap gap-2 text-xs font-semibold"><span class="rounded-full bg-emerald-400/15 px-3 py-1.5 text-emerald-300">3 áreas operacionais disponíveis</span><span class="rounded-full bg-white/10 px-3 py-1.5 text-stone-300">Próximos módulos em preparação</span></div>
    </section>

    <section class="mt-8">
        <div class="mb-4"><h2 class="text-xl font-bold">Operação</h2><p class="mt-1 text-sm text-stone-600">Acesse as áreas disponíveis e acompanhe a evolução do sistema.</p></div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <a href="{{ route('ingredients.index') }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-amber-300 hover:shadow-md"><span class="text-xs font-bold uppercase tracking-wide text-emerald-700">Disponível</span><strong class="mt-2 block text-lg">Insumos</strong><span class="mt-1 block text-sm text-stone-500">Compras, preços e custos normalizados</span></a>
            <a href="{{ route('preparations.index') }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-amber-300 hover:shadow-md"><span class="text-xs font-bold uppercase tracking-wide text-emerald-700">Disponível</span><strong class="mt-2 block text-lg">Preparo de Recheios</strong><span class="mt-1 block text-sm text-stone-500">Ingredientes, rendimento, GLP e custos</span></a>
            <a href="{{ route('locations.index') }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-amber-300 hover:shadow-md"><span class="text-xs font-bold uppercase tracking-wide text-emerald-700">Disponível</span><strong class="mt-2 block text-lg">Unidades</strong><span class="mt-1 block text-sm text-stone-500">Fábrica e lojas da operação</span></a>

            @foreach (['Montagem das Coxinhas', 'Produção', 'Produtos', 'Entradas / Recebimentos', 'Vendas', 'Perdas', 'Relatórios'] as $module)
                <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 p-5 text-stone-500"><span class="text-xs font-bold uppercase tracking-wide text-stone-400">Em breve</span><strong class="mt-2 block text-lg text-stone-600">{{ $module }}</strong><span class="mt-1 block text-sm">Será liberado em um próximo incremento.</span></div>
            @endforeach
        </div>
    </section>

    <section class="mt-8 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6"><div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div><h2 class="text-lg font-bold">Configurações técnicas</h2><p class="mt-1 text-sm text-stone-600">Cadastros usados internamente pelos cálculos e pela operação.</p></div><div class="flex flex-wrap gap-2"><a class="btn-secondary" href="{{ route('ingredient-categories.index') }}">Categorias de insumos</a><a class="btn-secondary" href="{{ route('suppliers.index') }}">Fornecedores</a><a class="btn-secondary" href="{{ route('equipment.index') }}">Equipamentos</a><a class="btn-secondary" href="{{ route('glp-products.index') }}">GLP / Energia</a></div></div></section>
@endsection
