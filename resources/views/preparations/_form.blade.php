@php($item = $preparation ?? null)
@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2"><label class="form-label" for="name">Nome</label><input class="form-input" id="name" name="name" required value="{{ old('name', $item?->name) }}"></div>
    <div class="sm:col-span-2"><label class="form-label" for="description">Descrição</label><textarea class="form-input" id="description" name="description" rows="3">{{ old('description', $item?->description) }}</textarea></div>
    <div><label class="form-label" for="initial_quantity">Quantidade/peso inicial</label><input class="form-input" id="initial_quantity" name="initial_quantity" type="number" min="0.000001" step="0.000001" value="{{ old('initial_quantity', $item?->initial_quantity) }}"></div>
    <div><label class="form-label" for="initial_unit">Unidade inicial</label><select class="form-input" id="initial_unit" name="initial_unit"><option value="">Selecione</option>@foreach (['kg' => 'kg', 'g' => 'g', 'l' => 'litro', 'ml' => 'ml', 'un' => 'unidade'] as $value => $label)<option value="{{ $value }}" @selected(old('initial_unit', $item?->initial_unit) === $value)>{{ $label }}</option>@endforeach</select></div>
    <div><label class="form-label" for="expected_yield">Rendimento esperado</label><input class="form-input" id="expected_yield" name="expected_yield" type="number" min="0.000001" step="0.000001" required value="{{ old('expected_yield', $item?->expected_yield) }}"></div>
    <div><label class="form-label" for="yield_unit">Unidade do rendimento</label><select class="form-input" id="yield_unit" name="yield_unit" required>@foreach (['kg' => 'kg', 'g' => 'g', 'l' => 'litro', 'ml' => 'ml', 'un' => 'unidade'] as $value => $label)<option value="{{ $value }}" @selected(old('yield_unit', $item?->yield_unit) === $value)>{{ $label }}</option>@endforeach</select></div>
    <div><label class="form-label" for="actual_final_quantity">Quantidade/peso final real</label><input class="form-input" id="actual_final_quantity" name="actual_final_quantity" type="number" min="0.000001" step="0.000001" value="{{ old('actual_final_quantity', $item?->actual_final_quantity) }}"><p class="mt-1 text-xs text-stone-500">Usa a unidade do rendimento.</p></div>
    <div><label class="form-label" for="total_preparation_time_minutes">Tempo total (minutos)</label><input class="form-input" id="total_preparation_time_minutes" name="total_preparation_time_minutes" type="number" min="1" step="1" required value="{{ old('total_preparation_time_minutes', $item?->total_preparation_time_minutes) }}"></div>
    <div class="sm:col-span-2"><label class="form-label" for="notes">Observações</label><textarea class="form-input" id="notes" name="notes" rows="3">{{ old('notes', $item?->notes) }}</textarea></div>
</div>

<label class="flex items-center gap-3 text-sm font-semibold"><input class="h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" type="checkbox" name="active" value="1" @checked(old('active', $item?->active ?? true))>Preparação ativa</label>
<div class="flex flex-wrap gap-3"><button class="btn-primary" type="submit">Salvar preparação</button><a class="btn-secondary" href="{{ $item ? route('preparations.show', $item) : route('preparations.index') }}">Cancelar</a></div>
