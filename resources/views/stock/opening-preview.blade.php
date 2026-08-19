@extends('layouts.app')
@section('title', 'Confirmar estoque inicial')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Confirmar estoque inicial</h1><p class="page-subtitle">Revise os dados reais antes de criar o movimento oficial.</p></div></div>
    <div class="form-card max-w-3xl">
        <dl class="grid gap-5 sm:grid-cols-2">
            <div><dt class="metric-label">Produto</dt><dd class="mt-1 text-lg font-bold">{{ $preview['product']->name }}</dd></div>
            <div><dt class="metric-label">Unidade/localização</dt><dd class="mt-1 text-lg font-bold">{{ $preview['location']->name }}</dd></div>
            <div><dt class="metric-label">Quantidade</dt><dd class="mt-1 text-lg font-bold">{{ \App\Support\DecimalFormatter::format($preview['quantity'], $preview['product']->stock_unit === 'un' ? 0 : 3) }} {{ $preview['product']->stock_unit }}</dd></div>
            <div><dt class="metric-label">Data real</dt><dd class="mt-1 text-lg font-bold">{{ \Illuminate\Support\Carbon::parse($preview['operation_date'])->format('d/m/Y') }}</dd></div>
            <div class="sm:col-span-2"><dt class="metric-label">Justificativa</dt><dd class="mt-1 whitespace-pre-line text-stone-800">{{ $preview['notes'] }}</dd></div>
        </dl>
        <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><strong>Atenção:</strong> esta confirmação cria um movimento imutável de saldo inicial. Nenhum saldo é editado diretamente.</div>
        <div class="mt-6 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('stock.opening.store') }}">@csrf<input type="hidden" name="preview_token" value="{{ $preview['token'] }}"><button class="btn-primary" type="submit">Confirmar e registrar</button></form>
            <a class="btn-secondary" href="{{ route('stock.opening.create', ['product_id' => $preview['product']->id, 'location_id' => $preview['location']->id]) }}">Cancelar e revisar</a>
        </div>
    </div>
@endsection
