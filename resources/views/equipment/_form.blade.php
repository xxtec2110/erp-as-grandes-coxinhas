@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="grid gap-5 sm:grid-cols-2">
    <div><label for="name" class="form-label">Nome</label><input id="name" name="name" class="form-input" required value="{{ old('name', $equipment?->name) }}"></div>
    <div><label for="type" class="form-label">Tipo</label><input id="type" name="type" class="form-input" required placeholder="Ex.: fogão industrial, forno, masseira" value="{{ old('type', $equipment?->type) }}"></div>
    <div class="sm:col-span-2"><label for="description" class="form-label">Descrição</label><textarea id="description" name="description" rows="3" class="form-input">{{ old('description', $equipment?->description) }}</textarea></div>
    <div><label for="energy_source" class="form-label">Fonte de energia</label><select id="energy_source" name="energy_source" class="form-input" required><option value="glp" @selected(old('energy_source', $equipment?->energy_source) === 'glp')>GLP</option><option value="electric" @selected(old('energy_source', $equipment?->energy_source) === 'electric')>Energia elétrica</option><option value="other" @selected(old('energy_source', $equipment?->energy_source) === 'other')>Outro</option></select></div>
    <div><label for="nominal_glp_consumption_kg_hour" class="form-label">Consumo nominal do equipamento (kg GLP/h)</label><input id="nominal_glp_consumption_kg_hour" name="nominal_glp_consumption_kg_hour" type="number" min="0.000001" step="0.000001" class="form-input" value="{{ old('nominal_glp_consumption_kg_hour', $equipment?->nominal_glp_consumption_kg_hour) }}"><p class="mt-2 text-xs text-stone-500">Opcional quando o consumo será controlado por queimador.</p></div>
    <div><label for="power" class="form-label">Potência</label><input id="power" name="power" type="number" min="0.0001" step="0.0001" class="form-input" value="{{ old('power', $equipment?->power) }}"></div>
    <div><label for="power_unit" class="form-label">Unidade da potência</label><input id="power_unit" name="power_unit" class="form-input" placeholder="Ex.: kW, kcal/h" value="{{ old('power_unit', $equipment?->power_unit) }}"></div>
    <div><label for="default_utilization_factor" class="form-label">Fator padrão de utilização</label><input id="default_utilization_factor" name="default_utilization_factor" type="number" min="0.001" max="1" step="0.001" class="form-input" required value="{{ old('default_utilization_factor', $equipment?->default_utilization_factor ?? '1.000') }}"><p class="mt-2 text-xs text-stone-500">1,000 representa 100% da carga nominal.</p></div>
    <label class="flex items-end gap-3 pb-8 text-sm font-medium"><input type="checkbox" name="active" value="1" class="h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" @checked(old('active', $equipment?->active ?? true))> Equipamento ativo</label>
    <div class="sm:col-span-2"><label for="notes" class="form-label">Observações</label><textarea id="notes" name="notes" rows="4" class="form-input">{{ old('notes', $equipment?->notes) }}</textarea></div>
</div>

<div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Salvar equipamento</button><a class="btn-secondary" href="{{ route('equipment.index') }}">Cancelar</a></div>
