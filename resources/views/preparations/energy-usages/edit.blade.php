@extends('layouts.app')
@section('title', 'Editar uso de GLP')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Editar uso de GLP</h1><p class="page-subtitle">{{ $preparation->name }} · {{ $energyUsage->equipment->name }} · {{ $energyUsage->burner?->name ?? 'Consumo geral' }}</p></div></div>
    <form class="form-card max-w-2xl space-y-6" method="POST" action="{{ route('preparations.energy-usages.update', [$preparation, $energyUsage]) }}">@csrf @method('PUT')
        @if ($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <input type="hidden" name="production_equipment_id" value="{{ $energyUsage->production_equipment_id }}"><input type="hidden" name="equipment_burner_id" value="{{ $energyUsage->equipment_burner_id }}"><input type="hidden" name="glp_product_id" value="{{ $energyUsage->glp_product_id }}">
        <div><p class="form-label">Recipiente/carga GLP</p><p class="rounded-xl bg-stone-100 px-4 py-3">{{ $energyUsage->glpProduct->name }}</p></div>
        <div class="grid gap-5 sm:grid-cols-2"><div><label class="form-label" for="usage_time_minutes">Tempo de uso (minutos)</label><input class="form-input" id="usage_time_minutes" name="usage_time_minutes" type="number" min="0.01" step="0.01" required value="{{ old('usage_time_minutes', $energyUsage->usage_time_minutes) }}"></div><div><label class="form-label" for="utilization_factor">Fator de utilização</label><input class="form-input" id="utilization_factor" name="utilization_factor" type="number" min="0.001" max="1" step="0.001" required value="{{ old('utilization_factor', $energyUsage->utilization_factor) }}"></div></div>
        <div class="flex gap-3"><button class="btn-primary" type="submit">Salvar alterações</button><a class="btn-secondary" href="{{ route('preparations.show', $preparation) }}">Cancelar</a></div>
    </form>
@endsection
