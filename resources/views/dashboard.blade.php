@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="page-header"><div><h1 class="page-title">Dashboard operacional</h1><p class="page-subtitle">Dados oficiais de {{ $startDate }} a {{ $endDate }}.</p></div></div>
<form method="GET" class="form-card mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
    <label><span class="form-label">Unidade</span><select class="form-input" name="location_id">@foreach($locations as $item)<option value="{{ $item->id }}" @selected($location?->id === $item->id)>{{ $item->name }}</option>@endforeach</select></label>
    <label><span class="form-label">Período</span><select class="form-input" name="period"><option value="today">Hoje</option><option value="week" @selected(request('period') === 'week')>Semana</option><option value="fortnight" @selected(request('period') === 'fortnight')>Quinzena</option><option value="month" @selected(request('period') === 'month')>Mês</option><option value="custom" @selected(request('period') === 'custom')>Personalizado</option></select></label>
    <label><span class="form-label">Início</span><input class="form-input" type="date" name="start_date" value="{{ request('start_date', $startDate) }}"></label>
    <label><span class="form-label">Fim</span><input class="form-input" type="date" name="end_date" value="{{ request('end_date', $endDate) }}"></label>
    <button class="btn-primary">Atualizar</button>
</form>
@php($metric = fn($key) => collect($summary[$key] ?? [])->sum(fn($value) => (float) $value))
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach([['production','Produção'],['outbound','Vendas / saídas'],['losses','Perdas'],['receipts','Recebimentos']] as [$key,$label])<div class="metric-card"><p class="metric-label">{{ $label }}</p><p class="metric-value">{{ \App\Support\DecimalFormatter::format((string) $metric($key), 0) }}</p></div>@endforeach
    <div class="metric-card"><p class="metric-label">Faturamento</p><p class="metric-value">R$ {{ \App\Support\DecimalFormatter::format($summary['revenue']['brl'] ?? '0', 2) }}</p></div>
    <div class="metric-card"><p class="metric-label">Taxas comerciais</p><p class="metric-value">R$ {{ \App\Support\DecimalFormatter::format($summary['fees']['brl'] ?? '0', 2) }}</p></div>
    <div class="metric-card"><p class="metric-label">Transferências em trânsito</p><p class="metric-value">{{ $inTransit }}</p></div>
    @if($openPayables !== null)<div class="metric-card"><p class="metric-label">Contas a pagar abertas</p><p class="metric-value">R$ {{ \App\Support\DecimalFormatter::format((string) $openPayables, 2) }}</p></div>@endif
</div>
@if($location?->daily_sales_target)<section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5"><p class="metric-label">Meta diária — {{ $location->name }}</p><p class="mt-2 text-2xl font-bold">Meta: {{ \App\Support\DecimalFormatter::format($location->daily_sales_target, 0) }} · Vendidas: {{ \App\Support\DecimalFormatter::format((string) $metric('outbound'), 0) }}</p></section>@endif
<section class="mt-8"><h2 class="mb-4 text-lg font-bold">Estoque atual — {{ $location?->name }}</h2><div class="table-card"><table class="data-table"><thead><tr><th>Produto</th><th>Saldo</th><th>Situação</th></tr></thead><tbody>@forelse($positions as $row)<tr><td>{{ $row['product']->name }}</td><td>{{ \App\Support\DecimalFormatter::format($row['balance'], $row['product']->stock_unit === 'un' ? 0 : 3) }} {{ $row['product']->stock_unit }}</td><td>{{ $row['situation']->label() }}</td></tr>@empty<tr><td colspan="3" class="empty-state">Nenhum produto cadastrado.</td></tr>@endforelse</tbody></table></div></section>
@endsection
