@extends('layouts.app')
@section('title', 'Revisar mappings de produtos')
@section('content')
    <div class="mx-auto max-w-5xl space-y-6">
        <header>
            <a class="text-sm font-bold text-amber-700" href="{{ route('pdv.mappings', [$connection, 'from' => $from, 'to' => $to, 'status' => 'unmapped']) }}">← Voltar sem salvar</a>
            <p class="mt-3 text-sm font-semibold uppercase tracking-wider text-amber-700">{{ $connection->location->name }}</p>
            <h1 class="text-3xl font-bold">Confirmar mappings selecionados</h1>
            <p class="mt-2 text-sm text-stone-600">Revise cada vínculo. Nada foi gravado até este momento.</p>
        </header>

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">Esta confirmação altera somente mappings. Não cria produtos, vendas, taxas ou movimentações de estoque.</div>

        <form method="POST" action="{{ route('pdv.mappings.products.batch.confirm', $connection) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="confirmed" value="1"><input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
            <input type="hidden" name="idempotency_key" value="{{ request('idempotency_key') }}"><input type="hidden" name="reason" value="{{ request('reason') }}">
            @foreach ($rows as $index => $row)
                <input type="hidden" name="rows[{{ $index }}][selected]" value="1">
                <input type="hidden" name="rows[{{ $index }}][external_product_id]" value="{{ $row['selection']['external_product_id'] }}">
                <input type="hidden" name="rows[{{ $index }}][product_id]" value="{{ $row['selection']['product_id'] }}">
                <input type="hidden" name="rows[{{ $index }}][confirm_remap]" value="{{ $row['selection']['confirm_remap'] ? 1 : 0 }}">
                <article class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-[1fr_auto_1fr] sm:items-center">
                    <div><p class="text-xs font-bold uppercase text-stone-500">GrandChef</p><p class="font-bold">{{ $row['entry']['description'] }}</p><p class="font-mono text-xs text-stone-500">{{ $row['entry']['external_product_id'] }}</p></div>
                    <div class="text-center text-2xl text-amber-700">→</div>
                    <div><p class="text-xs font-bold uppercase text-stone-500">ERP oficial</p><p class="font-bold">{{ $row['product']->name }}</p><p class="text-xs text-stone-500">ID {{ $row['product']->id }} · {{ $row['product']->category?->name ?? 'Sem categoria' }}</p></div>
                </article>
            @endforeach
            <div class="flex flex-wrap justify-end gap-3 pt-3">
                <a class="rounded-lg border px-5 py-3 font-bold" href="{{ route('pdv.mappings', [$connection, 'from' => $from, 'to' => $to, 'status' => 'unmapped']) }}">Cancelar sem salvar</a>
                <button class="rounded-lg bg-stone-900 px-5 py-3 font-bold text-white">Confirmar {{ $rows->count() }} mapping(s)</button>
            </div>
        </form>
    </div>
@endsection
