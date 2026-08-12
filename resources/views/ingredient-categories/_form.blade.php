@if ($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="grid gap-5">
    <div><label class="form-label" for="name">Nome</label><input class="form-input" id="name" name="name" maxlength="255" required value="{{ old('name', $ingredientCategory?->name) }}" placeholder="Ex.: Laticínios"></div>
    <div><label class="form-label" for="notes">Observações</label><textarea class="form-input" id="notes" name="notes" rows="4">{{ old('notes', $ingredientCategory?->notes) }}</textarea></div>
    <label class="flex items-center gap-3 text-sm font-medium"><input class="h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" type="checkbox" name="active" value="1" @checked(old('active', $ingredientCategory?->active ?? true))>Categoria ativa</label>
</div>
<div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Salvar categoria</button><a class="btn-secondary" href="{{ route('ingredient-categories.index') }}">Cancelar</a></div>
