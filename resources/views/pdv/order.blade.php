@extends('layouts.app')
@section('title', 'Pedido GrandChef')
@section('content')
    <div class="space-y-6">
        <div>
            <a class="text-sm font-bold text-amber-700" href="{{ route('pdv.reports.sales', $connection) }}">← Vendas GrandChef</a>
            <h1 class="mt-2 text-3xl font-bold">Pedido {{ $sale?->externalOrderNumber ?? $externalSaleId }}</h1>
            <p class="mt-2 text-sm text-stone-600">{{ $connection->location->name }} · consulta externa somente leitura</p>
        </div>

        @if ($error)
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950"><strong>Detalhe não consultado.</strong> {{ $error }}</div>
        @elseif (! $sale)
            <div class="rounded-xl border bg-white p-6 text-stone-500">Pedido não encontrado.</div>
        @else
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-stone-500">ID externo</p><p class="mt-1 break-all font-mono font-bold">{{ $sale->externalSaleId }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-stone-500">Data/hora</p><p class="mt-1 font-bold">{{ $sale->closedAt->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-stone-500">Status</p><p class="mt-1 font-bold">{{ $sale->status }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-stone-500">Total</p><p class="mt-1 text-xl font-bold">R$ {{ \App\Support\DecimalFormatter::format($sale->netAmount) }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-stone-500">Subtotal</p><p class="mt-1 font-bold">R$ {{ \App\Support\DecimalFormatter::format($sale->grossAmount) }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-stone-500">Desconto</p><p class="mt-1 font-bold">R$ {{ \App\Support\DecimalFormatter::format($sale->discountAmount) }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-stone-500">Pago informado</p><p class="mt-1 font-bold">{{ $sale->paidAmount !== null ? 'R$ '.\App\Support\DecimalFormatter::format($sale->paidAmount) : 'Não informado no cabeçalho' }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-stone-500">Troco</p><p class="mt-1 font-bold">{{ $sale->changeAmount !== null ? 'R$ '.\App\Support\DecimalFormatter::format($sale->changeAmount) : 'Não informado' }}</p></div>
            </section>

            <section class="rounded-xl border bg-white"><div class="border-b p-5"><h2 class="text-xl font-bold">Itens</h2></div><div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm"><thead class="bg-stone-50"><tr><th class="p-3">ID</th><th class="p-3">Produto</th><th class="p-3 text-right">Quantidade</th><th class="p-3 text-right">Unitário</th><th class="p-3 text-right">Desconto</th><th class="p-3 text-right">Total</th></tr></thead><tbody>
                @foreach ($sale->items as $item)<tr class="border-t"><td class="p-3 font-mono text-xs">{{ $item->externalProductId ?? $item->externalItemId }}</td><td class="p-3 font-semibold">{{ $item->name }}</td><td class="p-3 text-right">{{ \App\Support\DecimalFormatter::format($item->quantity, 3) }}</td><td class="p-3 text-right">R$ {{ \App\Support\DecimalFormatter::format($item->unitPrice) }}</td><td class="p-3 text-right">R$ {{ \App\Support\DecimalFormatter::format($item->discount) }}</td><td class="p-3 text-right font-bold">R$ {{ \App\Support\DecimalFormatter::format($item->total) }}</td></tr>@endforeach
            </tbody></table></div></section>

            <section class="rounded-xl border bg-white"><div class="border-b p-5"><h2 class="text-xl font-bold">Pagamentos</h2></div><div class="overflow-x-auto"><table class="w-full min-w-[700px] text-left text-sm"><thead class="bg-stone-50"><tr><th class="p-3">ID</th><th class="p-3">Forma</th><th class="p-3">Tipo / bandeira</th><th class="p-3">Status</th><th class="p-3 text-right">Valor</th><th class="p-3 text-right">Troco</th></tr></thead><tbody>
                @forelse ($sale->payments as $payment)<tr class="border-t"><td class="p-3 font-mono text-xs">{{ $payment->externalPaymentId }}</td><td class="p-3 font-semibold">{{ $payment->methodName ?? $payment->methodCode ?? 'Não informada' }}</td><td class="p-3">{{ $payment->type ?? '—' }}{{ $payment->brand ? ' · '.$payment->brand : '' }}</td><td class="p-3">{{ $payment->status ?? '—' }}</td><td class="p-3 text-right font-bold">R$ {{ \App\Support\DecimalFormatter::format($payment->amount) }}</td><td class="p-3 text-right">{{ $payment->changeAmount !== null ? 'R$ '.\App\Support\DecimalFormatter::format($payment->changeAmount) : '—' }}</td></tr>@empty<tr><td colspan="6" class="p-6 text-center text-stone-500">Nenhum pagamento retornado.</td></tr>@endforelse
            </tbody></table></div></section>
        @endif
    </div>
@endsection
