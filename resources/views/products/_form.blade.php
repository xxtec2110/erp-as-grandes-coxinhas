@if ($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if ($onboarding ?? null)
    <input type="hidden" name="pdv_connection_id" value="{{ $onboarding['connection']->id }}">
    <input type="hidden" name="external_product_id" value="{{ $onboarding['external_product_id'] }}">
    <input type="hidden" name="onboarding_from" value="{{ $onboarding['from'] }}">
    <input type="hidden" name="onboarding_to" value="{{ $onboarding['to'] }}">
    <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
        <strong>Onboarding humano a partir do GrandChef.</strong>
        <span class="mt-1 block">{{ $onboarding['description'] }} · código {{ $onboarding['external_product_code'] ?? '—' }} · {{ $onboarding['order_count'] }} pedido(s).</span>
        @if ($onboarding['prices']['same'])
            <span class="mt-1 block">Preço observado no GrandChef: R$ {{ \App\Support\DecimalFormatter::format($onboarding['prices']['latest'], 2) }}. É apenas sugestão de preenchimento.</span>
        @else
            <span class="mt-1 block">Preços observados: menor R$ {{ \App\Support\DecimalFormatter::format($onboarding['prices']['minimum'], 2) }}, maior R$ {{ \App\Support\DecimalFormatter::format($onboarding['prices']['maximum'], 2) }}, último R$ {{ \App\Support\DecimalFormatter::format($onboarding['prices']['latest'], 2) }}.</span>
        @endif
    </div>
    @if ($onboarding['category_gate'])
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><strong>Categoria oficial para bebidas precisa ser definida.</strong><span class="mt-1 block">O Product não será criado como Coxinha nem sem uma decisão humana de categoria.</span><a class="mt-3 inline-flex font-bold underline" href="{{ route('product-categories.index') }}">Abrir categorias de produtos</a></div>
    @endif
@endif
<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2"><label for="name" class="form-label">Nome</label><input id="name" name="name" class="form-input" required maxlength="255" value="{{ old('name', $product?->name ?? ($onboarding['suggested_name'] ?? null)) }}" placeholder="Ex.: Frango com Catupiry"></div>
    <div class="sm:col-span-2">
        <label for="product_category_id" class="form-label">Categoria</label>
        <select id="product_category_id" name="product_category_id" class="form-input">
            <option value="">Sem categoria</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('product_category_id', $product?->product_category_id ?? ($onboarding['suggested_category']?->id ?? null)) === (string) $category->id)>{{ $category->name }}</option>
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
    <div><label for="selling_price" class="form-label">Preço de venda atual (R$)</label><input id="selling_price" name="selling_price" inputmode="decimal" class="form-input" value="{{ old('selling_price', $product?->currentPrice?->price ?? (($onboarding['prices']['same'] ?? false) ? $onboarding['prices']['latest'] : null)) }}" placeholder="Ex.: 22.00"><p class="mt-2 text-xs text-stone-500">Alterações preservam o histórico de preços. O preço observado nunca é salvo apenas por abrir a tela.</p></div>
    <label class="flex items-end gap-3 pb-3 text-sm font-medium"><input type="checkbox" name="active" value="1" class="h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" @checked(old('active', $product?->active ?? true))> Produto ativo</label>
    <div class="sm:col-span-2">
        <label for="aliases_text" class="form-label">Aliases administrativos</label>
        <textarea id="aliases_text" name="aliases_text" class="form-input" rows="4" maxlength="5200" placeholder="Um nome alternativo por linha">{{ old('aliases_text', $product?->aliases?->pluck('name')->implode("\n")) }}</textarea>
        <p class="mt-2 text-xs text-stone-500">Use apenas variações oficiais conhecidas, uma por linha. O agente nunca cria aliases automaticamente.</p>
    </div>
</div>
<div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit" @disabled(($onboarding['category_gate'] ?? false))>Salvar produto</button><a class="btn-secondary" href="{{ ($onboarding ?? null) ? route('pdv.mappings', [$onboarding['connection'], 'from' => $onboarding['from'], 'to' => $onboarding['to'], 'status' => 'unmapped']) : route('products.index') }}">Cancelar</a></div>
