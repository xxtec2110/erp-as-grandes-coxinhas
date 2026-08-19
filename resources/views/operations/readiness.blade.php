@extends('layouts.app')
@section('title', 'Preparação para operação')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Preparação para operação</h1><p class="page-subtitle">Checklist dinâmico dos cadastros reais necessários para iniciar a operação.</p></div></div>
    <div class="mb-6 rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm text-sky-950">Os números abaixo vêm do banco atual. O sistema não cria ingredientes, preços, receitas, custos, metas ou saldos automaticamente.</div>
    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            ['Insumos com preço atual', $readiness['ingredients']['with_price'], $readiness['ingredients']['total'], route('ingredients.index')],
            ['Fornecedores ativos', $readiness['suppliers']['total'], null, route('suppliers.index')],
            ['Preparos com custo completo', $readiness['preparations']['complete'], $readiness['preparations']['total'], route('preparations.index')],
            ['Produtos com ficha técnica', $readiness['products']['with_recipe'], $readiness['products']['total'], route('products.index')],
            ['Produtos com custo calculado', $readiness['products']['with_cost'], $readiness['products']['total'], route('costs.index')],
            ['Unidades com estoque inicial iniciado', $readiness['opening_stock']['started'], $readiness['opening_stock']['total'], route('stock.opening.create')],
            ['Lojas com meta configurada', $readiness['targets']['configured'], $readiness['targets']['total'], route('locations.index')],
        ] as [$label, $done, $total, $url])
            <a class="metric-card transition hover:border-amber-300 hover:bg-amber-50" href="{{ $url }}"><p class="metric-label">{{ $label }}</p><p class="metric-value mt-2 text-2xl">{{ $done }}{{ $total !== null ? ' / '.$total : '' }}</p><p class="mt-2 text-sm text-stone-500">Abrir cadastro →</p></a>
        @endforeach
    </section>
    <section class="mt-8 form-card"><h2 class="text-xl font-bold">Estoque inicial por unidade</h2><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($readiness['opening_stock']['locations'] as $row)<div class="rounded-xl border border-stone-200 p-4"><div class="flex items-center justify-between gap-3"><strong>{{ $row['location']->name }}</strong><span class="status-badge {{ $row['started'] ? 'status-active' : 'status-inactive' }}">{{ $row['started'] ? 'Iniciado' : 'Não informado' }}</span></div><p class="mt-2 text-sm text-stone-500">{{ $row['products'] }} produto(s) com movimento de saldo inicial.</p></div>@endforeach</div></section>
@endsection
