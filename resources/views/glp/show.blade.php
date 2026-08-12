@extends('layouts.app')
@section('title', $glpProduct->name)
@section('content')
    <div class="page-header"><div><div class="flex flex-wrap items-center gap-3"><h1 class="page-title">GLP {{ $glpProduct->name }}</h1><span class="status-badge {{ $glpProduct->active ? 'status-active' : 'status-inactive' }}">{{ $glpProduct->active ? 'Ativo' : 'Inativo' }}</span></div><p class="page-subtitle">Peso líquido padrão: {{ \App\Support\DecimalFormatter::format($glpProduct->net_weight_kg, 3) }} kg</p></div><a class="btn-secondary" href="{{ route('glp-products.edit', $glpProduct) }}">Editar recipiente</a></div>

    @if ($current = $glpProduct->currentPrice)
        <section class="mb-8 grid gap-4 sm:grid-cols-3"><div class="metric-card"><p class="metric-label">Preço atual da carga</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($current->total_price) }}</p></div><div class="metric-card"><p class="metric-label">Quantidade comprada</p><p class="metric-value text-xl">{{ \App\Support\DecimalFormatter::format($current->quantity_kg, 3) }} kg</p></div><div class="metric-card"><p class="metric-label">Custo atual por kg</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($current->unit_cost_per_kg, 4) }}</p></div></section>
    @else
        <div class="mb-8 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900">Este recipiente ainda não possui preço. O primeiro preço será atual automaticamente.</div>
    @endif

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.65fr)]">
        <section><h2 class="mb-4 text-xl font-bold">Histórico de preços</h2><div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Data</th><th>Quantidade</th><th>Preço pago</th><th>Custo/kg</th><th>Status</th></tr></thead><tbody>
            @forelse ($glpProduct->prices as $price)<tr><td>{{ $price->effective_date->format('d/m/Y') }}</td><td>{{ \App\Support\DecimalFormatter::format($price->quantity_kg, 3) }} kg</td><td>R$ {{ \App\Support\DecimalFormatter::format($price->total_price) }}</td><td>R$ {{ \App\Support\DecimalFormatter::format($price->unit_cost_per_kg, 4) }}</td><td>@if ($price->is_current)<span class="status-badge status-active">Atual</span>@else <span class="text-sm text-stone-500">Histórico</span>@endif</td></tr>
            @empty <tr><td colspan="5" class="empty-state">Nenhum preço registrado.</td></tr> @endforelse
        </tbody></table></div></div></section>

        <section><h2 class="mb-4 text-xl font-bold">Adicionar preço</h2><form method="POST" action="{{ route('glp-products.prices.store', $glpProduct) }}" class="form-card space-y-5">@csrf
            @if ($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div><label for="quantity_kg" class="form-label">Quantidade de GLP (kg)</label><input id="quantity_kg" name="quantity_kg" type="number" min="0.0001" step="0.0001" class="form-input" required value="{{ old('quantity_kg', $glpProduct->net_weight_kg) }}"></div>
            <div><label for="total_price" class="form-label">Preço total pago (R$)</label><input id="total_price" name="total_price" type="number" min="0" step="0.01" class="form-input" required value="{{ old('total_price') }}"></div>
            <div><label for="effective_date" class="form-label">Data do preço</label><input id="effective_date" name="effective_date" type="date" class="form-input" required value="{{ old('effective_date', now()->toDateString()) }}"></div>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="is_current" value="1" class="mt-0.5 h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" @checked(old('is_current', true))><span><strong class="block">Usar como preço atual</strong><span class="text-stone-500">O preço anterior continuará no histórico.</span></span></label>
            <button class="btn-primary w-full" type="submit">Registrar preço</button>
        </form></section>
    </div>
@endsection
