@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <p class="font-semibold">Revise os campos informados.</p>
        <ul class="mt-2 list-disc pl-5">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label for="name" class="form-label">Nome</label>
        <input id="name" name="name" class="form-input" required value="{{ old('name', $supplier?->name) }}">
    </div>
    <div>
        <label for="contact_name" class="form-label">Contato</label>
        <input id="contact_name" name="contact_name" class="form-input" value="{{ old('contact_name', $supplier?->contact_name) }}">
    </div>
    <div>
        <label for="phone" class="form-label">Telefone</label>
        <input id="phone" name="phone" class="form-input" inputmode="tel" value="{{ old('phone', $supplier?->phone) }}">
    </div>
    <div class="sm:col-span-2">
        <label for="notes" class="form-label">Observações</label>
        <textarea id="notes" name="notes" rows="4" class="form-input">{{ old('notes', $supplier?->notes) }}</textarea>
    </div>
    <label class="sm:col-span-2 flex items-center gap-3 text-sm font-medium">
        <input type="checkbox" name="active" value="1" class="h-5 w-5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" @checked(old('active', $supplier?->active ?? true))>
        Fornecedor ativo
    </label>
</div>

<div class="mt-6 flex flex-wrap gap-3">
    <button class="btn-primary" type="submit">Salvar fornecedor</button>
    <a class="btn-secondary" href="{{ route('suppliers.index') }}">Cancelar</a>
</div>
