@if ($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2"><label for="name" class="form-label">Nome</label><input id="name" name="name" class="form-input" required maxlength="255" value="{{ old('name', $product?->name) }}" placeholder="Ex.: Frango com Catupiry"></div>
    <div class="sm:col-span-2">
        <label for="product_category_id" class="form-label">Categoria</label>
        <select id="product_category_id" name="product_category_id" class="form-input">
            <option value="">Sem categoria</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('product_category_id', $product?->product_category_id) === (string) $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="stock_unit" class="form-label">Unidade de estoque</label>
        <select id="stock_unit" name="stock_unit" class="form-input" required>
            <option value="un" @selected(old('stock_unit', $product?->stock_unit ?? 'un') === 'un')>Unidade (un)</option>
            <option value="g" @selected(old('stock_unit', $product?->stock_unit) === 'g')>Grama (g)</option>
            <option value="ml" @selected(old('stock_unit', $product?->stock_unit) === 'ml')>Mililitro (ml)</option>
        </select>
        <p class="mt-2 text-xs text-stone-500">Não poderá ser alterada depois do primeiro movimento.</p>
    </div>
    <div><label for="sort_order" class="form-label">Ordem de exibição</label><input id="sort_order" name="sort_order" type="number" min="1" class="form-input" value="{{ old('sort_order', $product?->sort_order) }}" placeholder="Ex.: 1"></div>
    <div><label for="selling_price" class="form-label">Preço de venda atual (R$)</label><input id="selling_price" name="selling_price" inputmode="decimal" class="form-input" value="{{ old('selling_price', $product?->currentPrice?->price) }}" placeholder="Ex.: 22.00"><p class="mt-2 text-xs text-stone-500">Alterações preservam o histórico de preços.</p></div>
    <label class="flex items-end gap-3 pb-3 text-sm font-medium"><input type="checkbox" name="active" value="1" class="h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" @checked(old('active', $product?->active ?? true))> Produto ativo</label>
    <div class="sm:col-span-2">
        <label for="aliases_text" class="form-label">Aliases administrativos</label>
        <textarea id="aliases_text" name="aliases_text" class="form-input" rows="4" maxlength="5200" placeholder="Um nome alternativo por linha">{{ old('aliases_text', $product?->aliases?->pluck('name')->implode("\n")) }}</textarea>
        <p class="mt-2 text-xs text-stone-500">Use apenas variações oficiais conhecidas, uma por linha. O agente nunca cria aliases automaticamente.</p>
    </div>
</div>
<div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Salvar produto</button><a class="btn-secondary" href="{{ route('products.index') }}">Cancelar</a></div>
