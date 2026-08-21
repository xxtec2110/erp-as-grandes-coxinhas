@extends('layouts.app')
@section('title', 'Vendas GrandChef')
@section('content')
    <div class="space-y-6">
        <div>
            <a class="text-sm font-bold text-amber-700" href="{{ route('pdv.index') }}">← Integrações</a>
            <h1 class="mt-2 text-3xl font-bold">Vendas GrandChef · {{ $connection->location->name }}</h1>
            <p class="mt-2 text-sm text-stone-600">Consulta externa somente leitura. Não cria vendas, financeiro ou movimentos de estoque no ERP.</p>
        </div>

        <form method="GET" class="grid gap-4 rounded-xl border bg-white p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <label><span class="text-sm font-bold">De</span><input class="mt-1 w-full rounded-lg border p-3" type="date" name="from" value="{{ $from }}" required></label>
            <label><span class="text-sm font-bold">Até</span><input class="mt-1 w-full rounded-lg border p-3" type="date" name="to" value="{{ $to }}" required></label>
            <button class="rounded-lg bg-stone-900 px-5 py-3 font-bold text-white">Consultar</button>
        </form>

        @if ($error)
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-amber-950" role="alert">
                <p class="font-bold">Consulta não executada</p>
                <p class="mt-1 text-sm">{{ $error }}</p>
            </div>
        @endif

        @if ($report)
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['Pedidos', $report['summary']['orders']],
                    ['Itens vendidos', \App\Support\DecimalFormatter::format($report['summary']['items_quantity'], 3)],
                    ['Subtotal bruto', 'R$ '.\App\Support\DecimalFormatter::format($report['summary']['gross_amount'])],
                    ['Descontos', 'R$ '.\App\Support\DecimalFormatter::format($report['summary']['discount_amount'])],
                    ['Total vendido', 'R$ '.\App\Support\DecimalFormatter::format($report['summary']['total_amount'])],
                    ['Total pago', 'R$ '.\App\Support\DecimalFormatter::format($report['summary']['paid_amount'])],
                    ['Ticket médio', 'R$ '.\App\Support\DecimalFormatter::format($report['summary']['average_ticket'])],
                    ['Coxinhas confirmadas', \App\Support\DecimalFormatter::format($report['summary']['confirmed_coxinha_quantity'], 3)],
                ] as [$label, $value])
                    <div class="rounded-xl border bg-white p-4"><p class="text-sm text-stone-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold">{{ $value }}</p></div>
                @endforeach
            </section>

            @unless ($report['summary']['coxinha_count_complete'])
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"><strong>Contagem de coxinhas depende do mapeamento dos produtos.</strong> O número exibido considera somente vínculos confirmados com a categoria oficial Coxinhas.</div>
            @endunless
            @unless ($report['pagination']['complete'])
                <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900"><strong>Resultado parcial.</strong> A paginação não pôde ser comprovada como completa; os totais não devem ser tratados como fechamento.</div>
            @endunless

            <section class="rounded-xl border bg-white">
                <div class="border-b p-5"><h2 class="text-xl font-bold">Itens vendidos</h2></div>
                <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm"><thead class="bg-stone-50"><tr><th class="p-3">ID externo</th><th class="p-3">Código</th><th class="p-3">Produto</th><th class="p-3 text-right">Quantidade</th><th class="p-3 text-right">Unitário</th><th class="p-3 text-right">Total</th></tr></thead><tbody>
                    @forelse ($report['items'] as $item)
                        <tr class="border-t"><td class="p-3 font-mono text-xs">{{ $item['external_product_id'] ?? '—' }}</td><td class="p-3">{{ $item['sku'] ?? '—' }}</td><td class="p-3 font-semibold">{{ $item['name'] }}</td><td class="p-3 text-right">{{ \App\Support\DecimalFormatter::format($item['quantity'], 3) }}</td><td class="p-3 text-right">{{ $item['unit_price'] !== null ? 'R$ '.\App\Support\DecimalFormatter::format($item['unit_price']) : 'Variável' }}</td><td class="p-3 text-right font-bold">R$ {{ \App\Support\DecimalFormatter::format($item['total']) }}</td></tr>
                    @empty <tr><td colspan="6" class="p-6 text-center text-stone-500">Nenhum item no período.</td></tr> @endforelse
                </tbody></table></div>
            </section>

            <section class="rounded-xl border bg-white">
                <div class="border-b p-5"><h2 class="text-xl font-bold">Formas de pagamento</h2></div>
                <div class="overflow-x-auto"><table class="w-full min-w-[620px] text-left text-sm"><thead class="bg-stone-50"><tr><th class="p-3">Código</th><th class="p-3">Forma</th><th class="p-3">Tipo</th><th class="p-3 text-right">Ocorrências</th><th class="p-3 text-right">Valor</th></tr></thead><tbody>
                    @forelse ($report['payments'] as $payment)
                        <tr class="border-t"><td class="p-3">{{ $payment['method_code'] ?? '—' }}</td><td class="p-3 font-semibold">{{ $payment['method_name'] ?? 'Não informada' }}</td><td class="p-3">{{ $payment['type'] ?? '—' }}</td><td class="p-3 text-right">{{ $payment['occurrences'] }}</td><td class="p-3 text-right font-bold">R$ {{ \App\Support\DecimalFormatter::format($payment['amount']) }}</td></tr>
                    @empty <tr><td colspan="5" class="p-6 text-center text-stone-500">Nenhum pagamento retornado.</td></tr> @endforelse
                </tbody></table></div>
            </section>

            <section class="rounded-xl border bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b p-5"><h2 class="text-xl font-bold">Pedidos</h2><p class="text-xs text-stone-500">{{ $report['pagination']['pages'] }} página(s) · total informado: {{ $report['pagination']['reported_total'] ?? 'não informado' }}</p></div>
                <div class="overflow-x-auto"><table class="w-full min-w-[1050px] text-left text-sm"><thead class="bg-stone-50"><tr><th class="p-3">Pedido</th><th class="p-3">Data/hora</th><th class="p-3">Status</th><th class="p-3 text-right">Itens</th><th class="p-3 text-right">Subtotal</th><th class="p-3 text-right">Desconto</th><th class="p-3 text-right">Total</th><th class="p-3 text-right">Pago</th><th class="p-3"></th></tr></thead><tbody>
                    @forelse ($report['orders'] as $sale)
                        @php($paid = $sale->paidAmount ?? (string) collect($sale->payments)->reduce(fn ($total, $payment) => \Brick\Math\BigDecimal::of($total)->plus($payment->amount), \Brick\Math\BigDecimal::zero()))
                        <tr class="border-t"><td class="p-3"><span class="block font-mono text-xs">{{ $sale->externalSaleId }}</span><span class="text-stone-500">{{ $sale->externalOrderNumber ?? '—' }}</span></td><td class="p-3">{{ $sale->closedAt->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</td><td class="p-3">{{ $sale->status }}</td><td class="p-3 text-right">{{ count($sale->items) }}</td><td class="p-3 text-right">R$ {{ \App\Support\DecimalFormatter::format($sale->grossAmount) }}</td><td class="p-3 text-right">R$ {{ \App\Support\DecimalFormatter::format($sale->discountAmount) }}</td><td class="p-3 text-right font-bold">R$ {{ \App\Support\DecimalFormatter::format($sale->netAmount) }}</td><td class="p-3 text-right">R$ {{ \App\Support\DecimalFormatter::format($paid) }}</td><td class="p-3 text-right"><a class="font-bold text-amber-700" href="{{ route('pdv.reports.orders.show', [$connection, $sale->externalSaleId]) }}">Detalhar</a></td></tr>
                    @empty <tr><td colspan="9" class="p-6 text-center text-stone-500">Nenhum pedido no período.</td></tr> @endforelse
                </tbody></table></div>
            </section>
        @endif
    </div>
@endsection
