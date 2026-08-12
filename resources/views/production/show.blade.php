@extends('layouts.app')
@section('title', 'Produção #'.$production->id)
@section('content')
    <div class="page-header"><div><div class="flex flex-wrap items-center gap-3"><h1 class="page-title">Produção #{{ $production->id }}</h1><span class="status-badge {{ $production->status === \App\Enums\ProductionStatus::Completed ? 'status-active' : 'status-inactive' }}">{{ $production->status->label() }}</span></div><p class="page-subtitle">{{ $production->product->name }} · {{ $production->location->name }}</p></div><a class="btn-secondary" href="{{ route('production.index') }}">Voltar</a></div>
    @if ($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="metric-card"><p class="metric-label">Data da operação</p><p class="metric-value">{{ $production->operation_date->format('d/m/Y') }}</p></div>
        <div class="metric-card"><p class="metric-label">Planejado</p><p class="metric-value">{{ \App\Support\DecimalFormatter::format($production->planned_quantity, $production->product->stock_unit === 'un' ? 0 : 3) }} {{ $production->product->stock_unit }}</p></div>
        <div class="metric-card"><p class="metric-label">Produzido</p><p class="metric-value">{{ $production->actual_quantity !== null ? \App\Support\DecimalFormatter::format($production->actual_quantity, $production->product->stock_unit === 'un' ? 0 : 3).' '.$production->product->stock_unit : 'Aguardando conclusão' }}</p></div>
        <div class="metric-card"><p class="metric-label">Registrado por</p><p class="metric-value">{{ $production->creator?->name ?? 'Sistema' }}</p></div>
    </div>
    @if ($production->status === \App\Enums\ProductionStatus::Planned)
        <div class="grid gap-6 lg:grid-cols-2">
            <form method="POST" action="{{ route('production.complete', $production) }}" class="form-card">@csrf<h2 class="mb-4 text-lg font-bold">Concluir produção</h2><label class="form-label" for="actual_quantity">Quantidade realmente produzida</label><input id="actual_quantity" name="actual_quantity" type="number" min="0.000001" step="0.000001" class="form-input" required value="{{ old('actual_quantity', $production->planned_quantity) }}"><p class="mt-3 text-sm text-stone-500">Ao concluir, esta quantidade será adicionada ao estoque de {{ $production->location->name }}.</p><button class="btn-primary mt-5" type="submit">Concluir e atualizar estoque</button></form>
            <form method="POST" action="{{ route('production.cancel', $production) }}" class="form-card">@csrf<h2 class="mb-4 text-lg font-bold">Cancelar planejamento</h2><p class="text-sm text-stone-600">O cancelamento não gera movimento de estoque.</p><button class="btn-secondary mt-5" type="submit">Cancelar produção</button></form>
        </div>
    @else
        <div class="form-card"><p class="text-sm text-stone-600">{{ $production->notes ?: 'Sem observações.' }}</p>@if ($production->status === \App\Enums\ProductionStatus::Completed)<p class="mt-3 text-sm font-semibold text-emerald-800">Movimento oficial de estoque registrado com rastreabilidade da produção.</p>@endif</div>
    @endif
@endsection
