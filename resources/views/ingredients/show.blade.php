@extends('layouts.app')
@section('title', $ingredient->name)
@section('content')
    <div class="page-header">
        <div><div class="flex flex-wrap items-center gap-3"><h1 class="page-title">{{ $ingredient->name }}</h1><span class="status-badge {{ $ingredient->active ? 'status-active' : 'status-inactive' }}">{{ $ingredient->active ? 'Ativo' : 'Inativo' }}</span></div><p class="page-subtitle">{{ $ingredient->category?->name ?? 'Sem categoria' }} · {{ $ingredient->brand ?: 'Sem marca' }} · Unidade-base: {{ $ingredient->base_unit }}</p></div>
        <a class="btn-secondary" href="{{ route('ingredients.edit', $ingredient) }}">Editar insumo</a>
    </div>

    <section class="mb-8 grid gap-4 sm:grid-cols-2">
        <div class="metric-card"><p class="metric-label">Conceito semântico</p><p class="metric-value text-xl">{{ $semanticResolution['concept_label'] ?? 'Não aplicável' }}</p><p class="mt-1 text-sm text-stone-500">{{ ($semanticResolution['concept_label'] ?? null) ? 'Resolução: '.str_replace('_', ' ', $semanticResolution['resolution_source'] ?? $semanticResolution['status']) : 'Insumo operacional identificado pelo próprio cadastro.' }}</p></div>
        <div class="metric-card"><p class="metric-label">Preço anterior</p>@if($previousPrice)<p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($previousPrice->base_unit_cost, 4) }} / {{ $ingredient->base_unit }}</p><p class="mt-1 text-sm text-stone-500">{{ $previousPrice->supplier->name }} · {{ $previousPrice->effective_date->format('d/m/Y') }}</p>@else<p class="metric-value text-xl">Ainda não existe</p><p class="mt-1 text-sm text-stone-500">O histórico será preservado quando novos preços forem registrados.</p>@endif</div>
    </section>

    @if ($current = $ingredient->currentPrice)
        @php
            $largeUnit = match ($ingredient->base_unit) { 'g' => 'kg', 'ml' => 'l', default => 'un' };
            $largeCost = $conversion->costForDisplayUnit($current->base_unit_cost, $largeUnit);
        @endphp
        <section class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="metric-card"><p class="metric-label">Fornecedor atual</p><p class="metric-value text-xl">{{ $current->supplier->name }}</p></div>
            <div class="metric-card"><p class="metric-label">Última compra</p><p class="metric-value text-xl">{{ \App\Support\DecimalFormatter::format($current->purchase_quantity, 3) }} {{ $current->purchase_unit }}</p><p class="mt-1 text-sm text-stone-500">R$ {{ \App\Support\DecimalFormatter::format($current->price_paid) }}</p></div>
            <div class="metric-card"><p class="metric-label">Custo por {{ $ingredient->base_unit }}</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($current->base_unit_cost, 4) }}</p></div>
            <div class="metric-card"><p class="metric-label">Custo por {{ $largeUnit }}</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($largeCost) }}</p></div>
        </section>
    @else
        <div class="mb-8 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900">Este insumo ainda não possui preço. O primeiro preço registrado será definido como atual automaticamente.</div>
    @endif

    <section class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="metric-card"><p class="metric-label">Compras em 30 dias</p><p class="metric-value">{{ $priceSummary['count'] }}</p></div>
        <div class="metric-card"><p class="metric-label">Menor custo</p><p class="metric-value text-xl">{{ $priceSummary['minimum'] === null ? '—' : 'R$ '.\App\Support\DecimalFormatter::format($priceSummary['minimum'],4) }}</p></div>
        <div class="metric-card"><p class="metric-label">Maior custo</p><p class="metric-value text-xl">{{ $priceSummary['maximum'] === null ? '—' : 'R$ '.\App\Support\DecimalFormatter::format($priceSummary['maximum'],4) }}</p></div>
        <div class="metric-card"><p class="metric-label">Média ponderada</p><p class="metric-value text-xl">{{ $priceSummary['weighted_average'] === null ? '—' : 'R$ '.\App\Support\DecimalFormatter::format($priceSummary['weighted_average'],4) }}</p></div>
        <div class="metric-card"><p class="metric-label">Variação</p><p class="metric-value text-xl">{{ $priceSummary['variation_percentage'] === null ? '—' : \App\Support\DecimalFormatter::format($priceSummary['variation_percentage'],2).'%' }}</p></div>
    </section>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.7fr)]">
        <section>
            <h2 class="mb-4 text-xl font-bold">Histórico de preços</h2>
            <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Data</th><th>Fornecedor</th><th>Compra</th><th>Preço</th><th>Custo-base</th><th>Origem</th><th>Status</th></tr></thead><tbody>
                @forelse ($ingredient->prices as $price)
                    <tr><td>{{ $price->effective_date->format('d/m/Y') }}</td><td class="font-medium">{{ $price->supplier->name }}</td><td>{{ \App\Support\DecimalFormatter::format($price->purchase_quantity, 3) }} {{ $price->purchase_unit }}</td><td>R$ {{ \App\Support\DecimalFormatter::format($price->price_paid) }}</td><td>R$ {{ \App\Support\DecimalFormatter::format($price->base_unit_cost, 4) }} / {{ $ingredient->base_unit }}</td><td>{{ str_replace('_',' ',$price->source_type ?? 'manual_price') }}@if($price->purchaseDocument) · <a class="text-link" href="{{ route('purchases.show',$price->purchaseDocument) }}">#{{ $price->purchase_document_id }}</a>@endif</td><td>@if ($price->is_current)<span class="status-badge status-active">Atual</span>@else <span class="text-sm text-stone-500">Histórico</span>@endif</td></tr>
                @empty <tr><td colspan="7" class="empty-state">Nenhum preço registrado.</td></tr> @endforelse
            </tbody></table></div></div>
        </section>

        <section>
            <h2 class="mb-4 text-xl font-bold">Adicionar preço</h2>
            <form method="POST" action="{{ route('ingredients.prices.store', $ingredient) }}" class="form-card space-y-5">
                @csrf
                @if ($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div><label for="supplier_id" class="form-label">Fornecedor</label><select id="supplier_id" name="supplier_id" class="form-input" required><option value="">Selecione</option>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select>@if ($suppliers->isEmpty())<p class="mt-2 text-xs text-red-700">Cadastre um fornecedor ativo antes de adicionar preços.</p>@endif</div>
                <div class="grid grid-cols-[minmax(0,1fr)_110px] gap-3"><div><label for="purchase_quantity" class="form-label">Quantidade comprada</label><input id="purchase_quantity" name="purchase_quantity" type="number" min="0.0001" step="0.0001" class="form-input" required value="{{ old('purchase_quantity') }}"></div><div><label for="purchase_unit" class="form-label">Unidade</label><select id="purchase_unit" name="purchase_unit" class="form-input" required>@foreach ($purchaseUnits as $unit)<option value="{{ $unit }}" @selected(old('purchase_unit') === $unit)>{{ $unit }}</option>@endforeach</select></div></div>
                <div><label for="price_paid" class="form-label">Preço total pago (R$)</label><input id="price_paid" name="price_paid" type="number" min="0" step="0.01" class="form-input" required value="{{ old('price_paid') }}"></div>
                <div><label for="effective_date" class="form-label">Data do preço</label><input id="effective_date" name="effective_date" type="date" class="form-input" required value="{{ old('effective_date', now()->toDateString()) }}"></div>
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="is_current" value="1" class="mt-0.5 h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" @checked(old('is_current', true))><span><strong class="block">Usar como preço atual</strong><span class="text-stone-500">O preço atual anterior continuará no histórico.</span></span></label>
                <button class="btn-primary w-full" type="submit" @disabled($suppliers->isEmpty())>Registrar preço</button>
            </form>
        </section>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <section class="form-card"><h2 class="text-lg font-bold">Comparação por fornecedor · 30 dias</h2><div class="mt-4 space-y-3">@forelse($supplierComparison as $row)<div class="flex flex-col gap-2 rounded-xl bg-stone-50 p-3 sm:flex-row sm:items-center sm:justify-between"><div><strong>{{ $row['supplier']->name }}</strong><p class="text-xs text-stone-500">{{ $row['count'] }} compra(s) · {{ \App\Support\DecimalFormatter::format($row['normalized_quantity'],3) }} {{ $ingredient->base_unit }}</p></div><div class="text-right"><strong>Último: R$ {{ \App\Support\DecimalFormatter::format($row['latest']->base_unit_cost,4) }} / {{ $ingredient->base_unit }}</strong><p class="text-xs text-stone-500">mín. {{ \App\Support\DecimalFormatter::format($row['minimum'],4) }} · máx. {{ \App\Support\DecimalFormatter::format($row['maximum'],4) }}</p><p class="text-xs text-stone-500">média {{ \App\Support\DecimalFormatter::format($row['average'],4) }} · ponderada {{ \App\Support\DecimalFormatter::format($row['weighted_average'],4) }}</p></div></div>@empty<p class="text-sm text-stone-500">Sem compras confirmadas no período.</p>@endforelse</div></section>
        <section class="form-card"><h2 class="text-lg font-bold">Produtos impactados</h2><p class="mt-1 text-sm text-stone-500">Uma mudança no preço atual recalcula somente estas fichas relacionadas.</p><div class="mt-4 flex flex-wrap gap-2">@forelse($impactedProducts as $product)<span class="status-badge">{{ $product->name }}</span>@empty<span class="text-sm text-stone-500">Nenhuma ficha utiliza este insumo.</span>@endforelse</div></section>
    </div>
@endsection
