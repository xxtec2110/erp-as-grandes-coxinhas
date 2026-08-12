@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="grid gap-5 sm:grid-cols-2">
    <div><label for="name" class="form-label">Nome do recipiente/carga</label><input id="name" name="name" class="form-input" required placeholder="Ex.: P45" value="{{ old('name', $glpProduct?->name) }}"></div>
    <div><label for="net_weight_kg" class="form-label">Peso líquido de GLP (kg)</label><input id="net_weight_kg" name="net_weight_kg" type="number" min="0.0001" step="0.0001" class="form-input" required value="{{ old('net_weight_kg', $glpProduct?->net_weight_kg) }}"></div>
    <div class="sm:col-span-2"><label for="notes" class="form-label">Observações</label><textarea id="notes" name="notes" rows="4" class="form-input">{{ old('notes', $glpProduct?->notes) }}</textarea></div>
    <label class="sm:col-span-2 flex items-center gap-3 text-sm font-medium"><input type="checkbox" name="active" value="1" class="h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" @checked(old('active', $glpProduct?->active ?? true))> Recipiente ativo</label>
</div>
<div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Salvar GLP</button><a class="btn-secondary" href="{{ route('glp-products.index') }}">Cancelar</a></div>
