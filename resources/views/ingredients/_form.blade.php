@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2"><label for="name" class="form-label">Nome</label><input id="name" name="name" class="form-input" required value="{{ old('name', $ingredient?->name) }}"></div>
    <div><label for="ingredient_category_id" class="form-label">Categoria</label><select id="ingredient_category_id" name="ingredient_category_id" class="form-input"><option value="">Sem categoria</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) old('ingredient_category_id', $ingredient?->ingredient_category_id) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select><p class="mt-2 text-xs text-stone-500">Categorias são gerenciadas em Configurações.</p></div>
    <div><label for="brand" class="form-label">Marca</label><input id="brand" name="brand" class="form-input" maxlength="255" value="{{ old('brand', $ingredient?->brand) }}" placeholder="Ex.: Catupiry, Vigor"></div>
    <div>
        <label for="base_unit" class="form-label">Unidade-base</label>
        <select id="base_unit" name="base_unit" class="form-input" required>
            <option value="g" @selected(old('base_unit', $ingredient?->base_unit) === 'g')>Grama (g)</option>
            <option value="ml" @selected(old('base_unit', $ingredient?->base_unit) === 'ml')>Mililitro (ml)</option>
            <option value="un" @selected(old('base_unit', $ingredient?->base_unit) === 'un')>Unidade (un)</option>
        </select>
        <p class="mt-2 text-xs text-stone-500">Não poderá ser alterada após registrar preços.</p>
    </div>
    <label class="flex items-end gap-3 pb-3 text-sm font-medium"><input type="checkbox" name="active" value="1" class="h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" @checked(old('active', $ingredient?->active ?? true))> Insumo ativo</label>
    <div class="sm:col-span-2"><label for="notes" class="form-label">Observação</label><textarea id="notes" name="notes" rows="4" class="form-input">{{ old('notes', $ingredient?->notes) }}</textarea></div>
</div>
<div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Salvar insumo</button><a class="btn-secondary" href="{{ route('ingredients.index') }}">Cancelar</a></div>
