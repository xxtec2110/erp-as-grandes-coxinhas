@extends('layouts.app')
@section('title', 'Prévia de Products oficiais')
@section('content')
    <div class="mx-auto max-w-5xl space-y-6">
        <header><a href="{{ route('pdv.go-live', [$connection, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="text-sm font-bold text-amber-700">← Voltar sem gravar</a><p class="mt-3 text-xs font-bold uppercase tracking-widest text-amber-700">Confirmação em duas etapas</p><h1 class="text-3xl font-bold">Prévia de Products oficiais</h1><p class="mt-2 text-sm text-stone-600">Nada foi gravado. A confirmação criará somente Products e históricos de preço pelo serviço oficial; os mappings continuarão pendentes.</p></header>
        <section class="space-y-3">@foreach ($preview['rows'] as $row)<article class="rounded-xl border bg-white p-5"><div class="flex flex-wrap justify-between gap-2"><h2 class="font-bold">{{ $row['name'] }}</h2><span class="rounded-full bg-stone-100 px-3 py-1 text-xs font-bold">{{ $row['active'] ? 'Ativo' : 'Inativo' }}</span></div><dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4"><div><dt class="text-stone-500">Externo</dt><dd>{{ $row['external_description'] }}</dd></div><div><dt class="text-stone-500">Categoria</dt><dd>{{ $row['category_name'] }}</dd></div><div><dt class="text-stone-500">Preço</dt><dd>R$ {{ \App\Support\DecimalFormatter::format($row['selling_price']) }}</dd></div><div><dt class="text-stone-500">Impacto observado</dt><dd>{{ $row['quantity_total'] }} un em {{ $row['order_count'] }} pedido(s)</dd></div></dl></article>@endforeach</section>
        <form method="POST" action="{{ route('pdv.go-live.products.confirm', $connection) }}" class="rounded-xl border border-amber-300 bg-amber-50 p-5">
            @csrf<input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
            <p class="text-sm text-amber-950">Prévia válida até {{ $preview['expires_at']->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }}. Mapping, venda e estoque não serão criados.</p>
            <label class="mt-4 flex gap-2 text-sm font-bold"><input type="checkbox" name="confirmed" value="1" class="mt-1 rounded border-stone-300"> Conferi todos os Products, categorias e preços acima.</label>
            <label class="mt-4 block text-sm font-bold">Digite CRIAR PRODUTOS para confirmar<input name="confirmation_text" autocomplete="off" class="mt-1 w-full rounded-lg border-stone-300 bg-white"></label>
            <button class="mt-4 rounded-lg bg-amber-700 px-4 py-2 text-sm font-bold text-white">Criar Products oficiais</button>
        </form>
    </div>
@endsection
