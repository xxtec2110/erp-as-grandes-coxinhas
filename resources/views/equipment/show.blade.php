@extends('layouts.app')
@section('title', $equipment->name)
@section('content')
    <div class="page-header"><div><div class="flex flex-wrap items-center gap-3"><h1 class="page-title">{{ $equipment->name }}</h1><span class="status-badge {{ $equipment->active ? 'status-active' : 'status-inactive' }}">{{ $equipment->active ? 'Ativo' : 'Inativo' }}</span></div><p class="page-subtitle">{{ $equipment->type }} · {{ $equipment->energySourceLabel() }}</p></div><a class="btn-secondary" href="{{ route('equipment.edit', $equipment) }}">Editar equipamento</a></div>

    <section class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="metric-card"><p class="metric-label">Consumo do equipamento</p><p class="metric-value text-xl">@if ($equipment->nominal_glp_consumption_kg_hour){{ \App\Support\DecimalFormatter::format($equipment->nominal_glp_consumption_kg_hour, 3) }} kg/h @else Não informado @endif</p></div>
        <div class="metric-card"><p class="metric-label">Potência</p><p class="metric-value text-xl">@if ($equipment->power){{ \App\Support\DecimalFormatter::format($equipment->power, 2) }} {{ $equipment->power_unit }} @else Não informada @endif</p></div>
        <div class="metric-card"><p class="metric-label">Fator padrão</p><p class="metric-value text-xl">{{ \App\Support\DecimalFormatter::format($equipment->default_utilization_factor, 3) }}</p></div>
        <div class="metric-card"><p class="metric-label">Queimadores cadastrados</p><p class="metric-value text-xl">{{ $equipment->burners->count() }}</p></div>
    </section>

    @if ($equipment->description || $equipment->notes)<section class="form-card mb-8"><h2 class="text-lg font-bold">Informações</h2>@if ($equipment->description)<p class="mt-3 text-stone-700">{{ $equipment->description }}</p>@endif @if ($equipment->notes)<p class="mt-3 whitespace-pre-line text-sm text-stone-600">{{ $equipment->notes }}</p>@endif</section>@endif

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.65fr)]">
        <section><h2 class="mb-4 text-xl font-bold">Queimadores / bocas</h2><div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Nome</th><th>Tipo</th><th>Consumo GLP</th><th>Fator</th><th>Status</th><th class="text-right">Ação</th></tr></thead><tbody>
            @forelse ($equipment->burners as $burner)<tr><td class="font-semibold">{{ $burner->name }}</td><td>{{ $burner->typeLabel() }}</td><td>{{ \App\Support\DecimalFormatter::format($burner->nominal_glp_consumption_kg_hour, 3) }} kg/h</td><td>{{ \App\Support\DecimalFormatter::format($burner->default_utilization_factor, 3) }}</td><td><span class="status-badge {{ $burner->active ? 'status-active' : 'status-inactive' }}">{{ $burner->active ? 'Ativo' : 'Inativo' }}</span></td><td class="text-right"><a class="text-link" href="{{ route('equipment.burners.edit', [$equipment, $burner]) }}">Editar</a></td></tr>
            @empty <tr><td colspan="6" class="empty-state">Nenhum queimador cadastrado.</td></tr> @endforelse
        </tbody></table></div></div></section>

        <section><h2 class="mb-4 text-xl font-bold">Adicionar queimador</h2>@if ($equipment->energy_source === 'glp')<form method="POST" action="{{ route('equipment.burners.store', $equipment) }}" class="form-card">@csrf @if ($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif @include('equipment.burners._form', ['burner' => null])<button class="btn-primary mt-6 w-full" type="submit">Cadastrar queimador</button></form>@else<div class="rounded-2xl border border-stone-200 bg-white p-5 text-sm text-stone-600">Queimadores individuais estão disponíveis para equipamentos cuja fonte de energia é GLP.</div>@endif</section>
    </div>
@endsection
