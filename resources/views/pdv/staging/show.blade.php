@extends('layouts.app')
@section('title', 'Pedido PDV preparado')
@section('content')
    @php($order = $preview['order'])
    @php($reconciliation = $preview['reconciliation'])
    <div class="space-y-6">
        <div>
            <a class="text-sm font-bold text-amber-700" href="{{ route('pdv.staging.index', [$connection, 'from' => $order->external_completed_at?->setTimezone(config('app.timezone'))->toDateString(), 'to' => $order->external_completed_at?->setTimezone(config('app.timezone'))->toDateString()]) }}">← Conferência</a>
            <p class="mt-3 text-sm font-semibold uppercase tracking-wider text-amber-700">{{ $order->location->name }}</p>
            <div class="flex flex-wrap items-center gap-3"><h1 class="text-3xl font-bold">Pedido {{ $order->external_code ?? $order->external_order_id }}</h1><span @class(['rounded-full px-3 py-1 text-xs font-bold', 'bg-emerald-100 text-emerald-800' => $reconciliation['ready_for_import'], 'bg-red-100 text-red-800' => ! $reconciliation['ready_for_import']])>{{ $reconciliation['ready_for_import'] ? 'READY' : 'BLOQUEADO' }}</span></div>
            <p class="mt-2 text-sm text-stone-600">Snapshot de staging. Nenhuma venda ou movimentação de estoque foi criada.</p>
        </div>

        @if ($reconciliation['blockers'])
            <section class="rounded-xl border border-red-200 bg-red-50 p-5"><h2 class="font-bold text-red-900">Bloqueios</h2><ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-900">@foreach ($reconciliation['blockers'] as $blocker)<li>{{ $blocker['message'] }}</li>@endforeach</ul></section>
        @endif
        @if ($reconciliation['warnings'])
            <section class="rounded-xl border border-amber-200 bg-amber-50 p-5"><h2 class="font-bold text-amber-900">Avisos</h2><ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">@foreach ($reconciliation['warnings'] as $warning)<li>{{ $warning['message'] }}</li>@endforeach</ul></section>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([['Subtotal', $order->subtotal], ['Desconto', $order->discount_total], ['Serviço', $order->service_total ?? '0'], ['Entrega', $order->delivery_total ?? '0'], ['Total', $order->total], ['Pago', $order->paid_total ?? '0'], ['Troco', $order->change_total ?? '0']] as [$label, $value])
                <div class="rounded-xl border bg-white p-4"><p class="text-xs font-bold uppercase text-stone-500">{{ $label }}</p><p class="mt-1 text-xl font-bold">R$ {{ \App\Support\DecimalFormatter::format($value) }}</p></div>
            @endforeach
        </section>

        <section class="rounded-xl border bg-white p-5">
            <h2 class="text-xl font-bold">Itens</h2>
            <div class="mt-4 overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm"><thead><tr class="border-b"><th class="p-3">Produto externo</th><th class="p-3">Produto ERP</th><th class="p-3 text-right">Quantidade</th><th class="p-3 text-right">Preço</th><th class="p-3 text-right">Total</th><th class="p-3">Mapping</th><th class="p-3 text-right">Saldo</th></tr></thead><tbody>
                @foreach ($reconciliation['product_mapping_status']['items'] as $mapped)
                    @php($item = $mapped['item'])
                    @php($stock = collect($reconciliation['stock_status']['products'])->first(fn ($entry) => $mapped['product'] && $entry['product']->id === $mapped['product']->id))
                    <tr @class(['border-b', 'opacity-50' => $item->cancelled])><td class="p-3"><strong>{{ $item->description }}</strong><span class="block font-mono text-xs text-stone-500">{{ $item->external_product_code ?? $item->external_product_id ?? 'sem ID' }}</span></td><td class="p-3">{{ $mapped['product']?->name ?? 'Não mapeado' }}</td><td class="p-3 text-right">{{ \App\Support\DecimalFormatter::format($item->quantity, 2) }}</td><td class="p-3 text-right">{{ $item->unit_price === null ? '—' : 'R$ '.\App\Support\DecimalFormatter::format($item->unit_price) }}</td><td class="p-3 text-right">R$ {{ \App\Support\DecimalFormatter::format($item->total) }}</td><td class="p-3">{{ $item->cancelled ? 'Item cancelado' : ($mapped['valid'] ? 'Confirmado' : 'Pendente') }}</td><td class="p-3 text-right">{{ $stock ? \App\Support\DecimalFormatter::format($stock['available'], 2) : '—' }}</td></tr>
                @endforeach
            </tbody></table></div>
        </section>

        <section class="rounded-xl border bg-white p-5">
            <h2 class="text-xl font-bold">Pagamentos</h2>
            <p class="mt-1 text-sm text-stone-500">Todos os pagamentos externos são preservados individualmente. Nenhuma taxa operacional é calculada nesta etapa.</p>
            <div class="mt-4 overflow-x-auto"><table class="w-full min-w-[700px] text-left text-sm"><thead><tr class="border-b"><th class="p-3">Forma externa</th><th class="p-3">Tipo</th><th class="p-3 text-right">Valor</th><th class="p-3 text-right">Taxa externa</th><th class="p-3">Mapping financeiro</th><th class="p-3">Estado</th></tr></thead><tbody>
                @foreach ($reconciliation['payment_mapping_status']['payments'] as $mapped)
                    @php($payment = $mapped['payment'])
                    <tr class="border-b"><td class="p-3"><strong>{{ $payment->external_form_description ?? 'Não informado' }}</strong><span class="block font-mono text-xs text-stone-500">{{ $payment->external_form_id ?? 'sem ID' }}</span></td><td class="p-3">{{ $payment->external_type ?? '—' }}</td><td class="p-3 text-right font-bold">R$ {{ \App\Support\DecimalFormatter::format($payment->amount) }}</td><td class="p-3 text-right">{{ $payment->fees === null ? '—' : 'R$ '.\App\Support\DecimalFormatter::format($payment->fees) }}</td><td class="p-3">{{ $mapped['mapping']?->payment_method ?? 'Pendente' }}</td><td class="p-3">{{ $payment->external_status ?? '—' }}</td></tr>
                @endforeach
            </tbody></table></div>
        </section>
    </div>
@endsection
