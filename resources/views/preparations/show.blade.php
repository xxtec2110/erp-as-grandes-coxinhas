@extends('layouts.app')
@section('title', $preparation->name)
@section('content')
    @php($yield = $calculation['yield'])
    @php($unitCosts = $calculation['unit_costs'])
    <div class="page-header"><div><div class="flex flex-wrap items-center gap-3"><h1 class="page-title">{{ $preparation->name }}</h1><span class="status-badge {{ $preparation->active ? 'status-active' : 'status-inactive' }}">{{ $preparation->active ? 'Ativa' : 'Inativa' }}</span></div><p class="page-subtitle">{{ $preparation->description ?: 'Receita base sem descrição.' }}</p></div><a class="btn-secondary" href="{{ route('preparations.edit', $preparation) }}">Editar preparação</a></div>
    @if ($errors->any())<div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if ($calculation['missing_price_count'] > 0)<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><strong>Cálculo incompleto:</strong> existem {{ $calculation['missing_ingredient_price_count'] }} ingrediente(s) e {{ $calculation['missing_glp_price_count'] }} uso(s) de GLP sem preço atual. Cadastre os preços para atualizar esta preparação automaticamente.</div>@endif

    <section class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="metric-card border-amber-300 bg-amber-50"><p class="metric-label">Custo total do preparo</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($calculation['total_preparation_cost']) }}</p></div>
        <div class="metric-card"><p class="metric-label">Custo dos ingredientes</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($calculation['total_ingredients_cost']) }}</p></div>
        <div class="metric-card"><p class="metric-label">Custo de GLP</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($calculation['total_energy_cost']) }}</p><p class="mt-1 text-sm text-stone-500">{{ \App\Support\DecimalFormatter::format($calculation['total_glp_consumption_kg'], 4) }} kg</p></div>
        <div class="metric-card"><p class="metric-label">Custos adicionais</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($calculation['total_additional_costs']) }}</p></div>
    </section>

    <section class="mb-8 grid gap-4 sm:grid-cols-3"><div class="metric-card"><p class="metric-label">Quantidade inicial</p><p class="metric-value text-xl">{{ $preparation->initial_quantity ? \App\Support\DecimalFormatter::format($preparation->initial_quantity, 3).' '.$preparation->initial_unit : 'Não informada' }}</p></div><div class="metric-card"><p class="metric-label">Quantidade final real</p><p class="metric-value text-xl">{{ $preparation->actual_final_quantity ? \App\Support\DecimalFormatter::format($preparation->actual_final_quantity, 3).' '.$preparation->yield_unit : 'Não informada' }}</p></div><div class="metric-card"><p class="metric-label">Tempo total</p><p class="metric-value text-xl">{{ $preparation->total_preparation_time_minutes }} min</p></div></section>

    @if ($yield)
        <section class="mb-8 grid gap-4 sm:grid-cols-3">
            <div class="metric-card"><p class="metric-label">Perda</p><p class="metric-value text-xl">{{ \App\Support\DecimalFormatter::format($yield['loss'], 3) }} {{ $yield['base_unit'] }}</p><p class="mt-1 text-sm text-stone-500">{{ \App\Support\DecimalFormatter::format($yield['loss_percentage'], 2) }}%</p></div>
            <div class="metric-card"><p class="metric-label">Rendimento percentual</p><p class="metric-value text-xl">{{ \App\Support\DecimalFormatter::format($yield['yield_percentage'], 2) }}%</p></div>
            <div class="metric-card"><p class="metric-label">Rendimento esperado</p><p class="metric-value text-xl">{{ \App\Support\DecimalFormatter::format($preparation->expected_yield, 3) }} {{ $preparation->yield_unit }}</p></div>
        </section>
    @endif

    @if ($unitCosts)
        <section class="mb-8 grid gap-4 sm:grid-cols-2">
            @if ($unitCosts['large_unit'])<div class="metric-card border-amber-200 bg-amber-50"><p class="metric-label">Custo por {{ $unitCosts['large_unit'] }}</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($unitCosts['large_unit_cost'], 4) }}</p></div>@endif
            <div class="metric-card border-amber-200 bg-amber-50"><p class="metric-label">Custo por {{ $unitCosts['base_unit'] }}</p><p class="metric-value text-xl">R$ {{ \App\Support\DecimalFormatter::format($unitCosts['base_unit_cost'], 6) }}</p></div>
        </section>
    @endif

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.55fr)]">
        <section><h2 class="mb-4 text-xl font-bold">Ingredientes</h2><div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Insumo</th><th>Quantidade</th><th>Custo/base</th><th>Custo total</th><th></th></tr></thead><tbody>
            @forelse ($calculation['ingredients'] as $row)<tr><td class="font-semibold">{{ $row['item']->ingredient->name }}</td><td>{{ \App\Support\DecimalFormatter::format($row['item']->quantity, 3) }} {{ $row['item']->unit }}</td><td>@if ($row['base_unit_cost'] !== null) R$ {{ \App\Support\DecimalFormatter::format($row['base_unit_cost'], 6) }}/{{ $row['item']->ingredient->base_unit }} @else <span class="text-amber-700">Sem preço atual</span> @endif</td><td>{{ $row['total_cost'] !== null ? 'R$ '.\App\Support\DecimalFormatter::format($row['total_cost']) : '—' }}</td><td class="text-right"><form method="POST" action="{{ route('preparations.ingredients.destroy', [$preparation, $row['item']]) }}">@csrf @method('DELETE')<button class="text-sm font-semibold text-red-700 hover:text-red-900" type="submit">Remover</button></form></td></tr>
            @empty <tr><td class="empty-state" colspan="5">Nenhum ingrediente adicionado.</td></tr> @endforelse
        </tbody></table></div></div></section>

        <section><h2 class="mb-4 text-xl font-bold">Adicionar ingrediente</h2><form class="form-card space-y-5" method="POST" action="{{ route('preparations.ingredients.store', $preparation) }}">@csrf
            <div><label class="form-label" for="ingredient_id">Insumo</label><select class="form-input" id="ingredient_id" name="ingredient_id" required><option value="">Selecione</option>@foreach ($ingredients as $ingredient)<option value="{{ $ingredient->id }}" @selected((string) old('ingredient_id') === (string) $ingredient->id)>{{ $ingredient->name }} ({{ $ingredient->base_unit }})</option>@endforeach</select></div>
            <div class="grid grid-cols-2 gap-4"><div><label class="form-label" for="quantity">Quantidade</label><input class="form-input" id="quantity" name="quantity" type="number" min="0.000001" step="0.000001" required value="{{ old('quantity') }}"></div><div><label class="form-label" for="unit">Unidade</label><select class="form-input" id="unit" name="unit" required>@foreach (['kg', 'g', 'l', 'ml', 'un'] as $unit)<option value="{{ $unit }}" @selected(old('unit') === $unit)>{{ $unit }}</option>@endforeach</select></div></div>
            <button class="btn-primary w-full" type="submit">Adicionar ingrediente</button>
        </form></section>
    </div>

    <div class="mt-10 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.55fr)]">
        <section><h2 class="mb-4 text-xl font-bold">Equipamentos, queimadores e GLP</h2><div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Equipamento / queimador</th><th>Tempo e fator</th><th>Consumo</th><th>Preço GLP</th><th>Custo</th><th></th></tr></thead><tbody>
            @forelse ($calculation['energy_usages'] as $row)<tr><td><strong class="block">{{ $row['usage']->equipment->name }}</strong><span class="text-sm text-stone-500">{{ $row['usage']->burner?->name ?? 'Consumo geral do equipamento' }} · {{ $row['usage']->glpProduct->name }}</span></td><td>{{ \App\Support\DecimalFormatter::format($row['usage']->usage_time_minutes, 2) }} min<br><span class="text-sm text-stone-500">Fator {{ \App\Support\DecimalFormatter::format($row['usage']->utilization_factor, 3) }}</span></td><td>{{ \App\Support\DecimalFormatter::format($row['consumption_kg'], 4) }} kg</td><td>{{ $row['glp_unit_cost'] !== null ? 'R$ '.\App\Support\DecimalFormatter::format($row['glp_unit_cost'], 4).'/kg' : 'Sem preço atual' }}</td><td>{{ $row['cost'] !== null ? 'R$ '.\App\Support\DecimalFormatter::format($row['cost']) : '—' }}</td><td class="whitespace-nowrap"><a class="mr-3 text-sm font-semibold text-amber-700" href="{{ route('preparations.energy-usages.edit', [$preparation, $row['usage']]) }}">Editar</a><form class="inline" method="POST" action="{{ route('preparations.energy-usages.destroy', [$preparation, $row['usage']]) }}">@csrf @method('DELETE')<button class="text-sm font-semibold text-red-700" type="submit">Remover</button></form></td></tr>
            @empty <tr><td class="empty-state" colspan="6">Nenhum uso de GLP adicionado.</td></tr> @endforelse
        </tbody></table></div></div></section>

        <section><h2 class="mb-4 text-xl font-bold">Adicionar uso de GLP</h2><form class="form-card space-y-5" method="POST" action="{{ route('preparations.energy-usages.store', $preparation) }}">@csrf
            <div><label class="form-label" for="production_equipment_id">Equipamento GLP</label><select class="form-input" id="production_equipment_id" name="production_equipment_id" required><option value="">Selecione</option>@foreach ($equipment as $item)<option value="{{ $item->id }}" @selected((string) old('production_equipment_id') === (string) $item->id)>{{ $item->name }}</option>@endforeach</select></div>
            <div><label class="form-label" for="equipment_burner_id">Queimador</label><select class="form-input" id="equipment_burner_id" name="equipment_burner_id"><option value="">Usar consumo geral do equipamento</option>@foreach ($equipment as $item)@foreach ($item->burners as $burner)<option value="{{ $burner->id }}" @selected((string) old('equipment_burner_id') === (string) $burner->id)>{{ $item->name }} — {{ $burner->name }} ({{ \App\Support\DecimalFormatter::format($burner->nominal_glp_consumption_kg_hour, 4) }} kg/h)</option>@endforeach @endforeach</select></div>
            <div><label class="form-label" for="glp_product_id">Recipiente/carga GLP</label><select class="form-input" id="glp_product_id" name="glp_product_id" required><option value="">Selecione</option>@foreach ($glpProducts as $product)<option value="{{ $product->id }}" @selected((string) old('glp_product_id') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></div>
            <div class="grid grid-cols-2 gap-4"><div><label class="form-label" for="usage_time_minutes">Tempo (min)</label><input class="form-input" id="usage_time_minutes" name="usage_time_minutes" type="number" min="0.01" step="0.01" required value="{{ old('usage_time_minutes') }}"></div><div><label class="form-label" for="utilization_factor">Fator de uso</label><input class="form-input" id="utilization_factor" name="utilization_factor" type="number" min="0.001" max="1" step="0.001" required value="{{ old('utilization_factor', '1.000') }}"></div></div>
            <p class="text-xs text-stone-500">1,000 representa 100% do consumo nominal cadastrado.</p><button class="btn-primary w-full" type="submit">Adicionar uso de GLP</button>
        </form></section>
    </div>

    <div class="mt-10 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.55fr)]">
        <section><h2 class="mb-4 text-xl font-bold">Custos adicionais</h2><div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Descrição</th><th>Valor</th><th></th></tr></thead><tbody>
            @forelse ($calculation['additional_costs'] as $cost)<tr><td class="font-semibold">{{ $cost->description }}</td><td>R$ {{ \App\Support\DecimalFormatter::format($cost->amount) }}</td><td class="text-right"><form method="POST" action="{{ route('preparations.additional-costs.destroy', [$preparation, $cost]) }}">@csrf @method('DELETE')<button class="text-sm font-semibold text-red-700" type="submit">Remover</button></form></td></tr>
            @empty <tr><td class="empty-state" colspan="3">Nenhum custo adicional.</td></tr> @endforelse
        </tbody></table></div></div></section>

        <section><h2 class="mb-4 text-xl font-bold">Adicionar custo</h2><form class="form-card space-y-5" method="POST" action="{{ route('preparations.additional-costs.store', $preparation) }}">@csrf<div><label class="form-label" for="description">Descrição</label><input class="form-input" id="description" name="description" maxlength="255" required value="{{ old('description') }}" placeholder="Ex.: mão de obra do lote"></div><div><label class="form-label" for="amount">Valor (R$)</label><input class="form-input" id="amount" name="amount" type="number" min="0" step="0.01" required value="{{ old('amount') }}"></div><button class="btn-primary w-full" type="submit">Adicionar custo</button></form></section>
    </div>
@endsection
