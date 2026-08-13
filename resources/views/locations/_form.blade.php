@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2"><label for="name" class="form-label">Nome</label><input id="name" name="name" class="form-input" required value="{{ old('name', $location?->name) }}"></div>
    <div><label for="type" class="form-label">Tipo</label><select id="type" name="type" class="form-input" required><option value="production" @selected(old('type', $location?->type) === 'production')>Produção</option><option value="store" @selected(old('type', $location?->type) === 'store')>Loja</option></select></div>
    <div><label for="daily_sales_target" class="form-label">Meta diária de vendas</label><input id="daily_sales_target" name="daily_sales_target" type="number" min="0" step="0.001" class="form-input" value="{{ old('daily_sales_target', $location?->daily_sales_target) }}"><p class="mt-1 text-xs text-stone-500">Opcional e configurável por unidade.</p></div>
    <label class="flex items-end gap-3 pb-3 text-sm font-medium"><input type="checkbox" name="active" value="1" class="h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" @checked(old('active', $location?->active ?? true))> Unidade ativa</label>
</div>
<div class="mt-6 flex flex-wrap gap-3"><button class="btn-primary" type="submit">Salvar unidade</button><a class="btn-secondary" href="{{ route('locations.index') }}">Cancelar</a></div>
