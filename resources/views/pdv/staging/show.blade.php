@extends('layouts.app')
@section('title', 'Prévia oficial do pedido PDV')
@section('content')
    @php($order = $preview['order'])
    @php($reconciliation = $preview['reconciliation'])
    <div class="space-y-6">
        <div>
            <a class="text-sm font-bold text-amber-700" href="{{ route('pdv.staging.index', [$connection, 'from' => $order->external_completed_at?->setTimezone(config('app.timezone'))->toDateString(), 'to' => $order->external_completed_at?->setTimezone(config('app.timezone'))->toDateString()]) }}">← Conferência</a>
            <p class="mt-3 text-sm font-semibold uppercase tracking-wider text-amber-700">{{ $order->location->name }}</p>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-bold">Pedido {{ $order->external_code ?? $order->external_order_id }}</h1>
                <span @class(['rounded-full px-3 py-1 text-xs font-bold', 'bg-emerald-100 text-emerald-800' => $importPlan['ready'], 'bg-red-100 text-red-800' => ! $importPlan['ready']])>{{ $importPlan['ready'] ? 'READY' : 'BLOQUEADO' }}</span>
            </div>
            <p class="mt-2 max-w-3xl text-sm text-stone-600">Plano puro de importação. Abrir ou atualizar esta tela não registra venda, pagamento ou movimentação de estoque.</p>
        </div>

        <section @class(['rounded-xl border p-5', 'border-red-300 bg-red-50' => ! $importPlan['import_enabled'], 'border-emerald-300 bg-emerald-50' => $importPlan['import_enabled']])>
            <h2 class="font-bold">Importação operacional {{ $importPlan['import_enabled'] ? 'habilitada' : 'desabilitada' }}</h2>
            <p class="mt-1 text-sm">Nenhuma operação será registrada enquanto a importação PDV estiver desabilitada.</p>
        </section>

        @if ($importPlan['blockers'])
            <section class="rounded-xl border border-red-200 bg-red-50 p-5">
                <h2 class="font-bold text-red-900">Bloqueios do backend</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-900">@foreach ($importPlan['blockers'] as $blocker)<li><strong>{{ $blocker['code'] }}</strong> — {{ $blocker['message'] }}</li>@endforeach</ul>
            </section>
        @endif
        @if ($importPlan['warnings'])
            <section class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                <h2 class="font-bold text-amber-900">Avisos</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">@foreach ($importPlan['warnings'] as $warning)<li>{{ $warning['message'] }}</li>@endforeach</ul>
            </section>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([['Subtotal externo', $order->subtotal], ['Desconto', $order->discount_total], ['Serviço', $order->service_total ?? '0'], ['Entrega', $order->delivery_total ?? '0'], ['Receita de produtos', $importPlan['totals']['product_revenue']], ['Total do pedido', $order->total], ['Pago', $order->paid_total ?? '0'], ['Troco', $order->change_total ?? '0']] as [$label, $value])
                <div class="rounded-xl border bg-white p-4"><p class="text-xs font-bold uppercase text-stone-500">{{ $label }}</p><p class="mt-1 text-xl font-bold">R$ {{ \App\Support\DecimalFormatter::format($value) }}</p></div>
            @endforeach
        </section>

        <section class="rounded-xl border bg-white p-5">
            <h2 class="text-xl font-bold">ProductSales previstos</h2>
            <p class="mt-1 text-sm text-stone-500">Uma linha oficial por item externo. Descontos e taxas são snapshots determinísticos.</p>
            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @forelse ($importPlan['items'] as $item)
                    <article class="rounded-lg border p-4 text-sm">
                        <div class="flex flex-wrap justify-between gap-2"><strong>{{ $item['product']->name }}</strong><span class="font-mono text-xs text-stone-500">{{ $item['item']->external_item_id }}</span></div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-stone-700">
                            <div><dt class="text-xs uppercase text-stone-500">Quantidade</dt><dd>{{ \App\Support\DecimalFormatter::format($item['quantity'], 2) }}</dd></div>
                            <div><dt class="text-xs uppercase text-stone-500">Preço unitário</dt><dd>R$ {{ \App\Support\DecimalFormatter::format($item['unit_price']) }}</dd></div>
                            <div><dt class="text-xs uppercase text-stone-500">Subtotal</dt><dd>R$ {{ \App\Support\DecimalFormatter::format($item['subtotal_amount']) }}</dd></div>
                            <div><dt class="text-xs uppercase text-stone-500">Desconto alocado</dt><dd>R$ {{ \App\Support\DecimalFormatter::format($item['discount_amount']) }}</dd></div>
                            <div><dt class="text-xs uppercase text-stone-500">Receita oficial</dt><dd class="font-bold">R$ {{ \App\Support\DecimalFormatter::format($item['total_amount']) }}</dd></div>
                            <div><dt class="text-xs uppercase text-stone-500">Taxa alocada</dt><dd>R$ {{ \App\Support\DecimalFormatter::format($item['fee_amount_snapshot']) }}</dd></div>
                        </dl>
                    </article>
                @empty
                    <p class="text-sm text-stone-500">Nenhum item pode ser convertido enquanto os mappings estiverem pendentes.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border bg-white p-5">
            <h2 class="text-xl font-bold">Pagamentos oficiais previstos</h2>
            <p class="mt-1 text-sm text-stone-500">Todos os pagamentos externos são preservados individualmente. Split, taxa fixa e percentual são calculados uma vez por pagamento.</p>
            <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($reconciliation['payment_mapping_status']['payments'] as $mapped)
                    <div class="rounded-lg bg-stone-50 p-3 text-sm"><strong>{{ $mapped['payment']->external_form_description ?? 'Forma não informada' }}</strong><span class="block">R$ {{ \App\Support\DecimalFormatter::format($mapped['payment']->amount) }}</span><span class="text-xs text-stone-500">{{ $mapped['mapping']?->payment_method ?? 'Mapping pendente' }}</span></div>
                @endforeach
            </div>
            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @forelse ($importPlan['payments'] as $payment)
                    <article class="rounded-lg border p-4 text-sm">
                        <div class="flex flex-wrap justify-between gap-2"><strong>{{ $payment['payment']->external_form_description }}</strong><span class="rounded-full bg-stone-100 px-2 py-1 text-xs font-bold uppercase">{{ $payment['payment_method'] }}</span></div>
                        <dl class="mt-3 grid grid-cols-2 gap-2">
                            <div><dt class="text-xs uppercase text-stone-500">Externo</dt><dd>R$ {{ \App\Support\DecimalFormatter::format($payment['external_amount']) }}</dd></div>
                            <div><dt class="text-xs uppercase text-stone-500">Após troco</dt><dd>R$ {{ \App\Support\DecimalFormatter::format($payment['amount']) }}</dd></div>
                            <div><dt class="text-xs uppercase text-stone-500">Taxa</dt><dd>R$ {{ \App\Support\DecimalFormatter::format($payment['fee_amount']) }}</dd></div>
                            <div><dt class="text-xs uppercase text-stone-500">Líquido</dt><dd class="font-bold">R$ {{ \App\Support\DecimalFormatter::format($payment['net_amount']) }}</dd></div>
                        </dl>
                        @if ($payment['allocations'] ?? [])
                            <details class="mt-3"><summary class="cursor-pointer font-semibold">Ver alocações</summary><ul class="mt-2 space-y-1 text-xs text-stone-600">@foreach ($payment['allocations'] as $allocation)<li>Item #{{ $allocation['item_id'] }}: bruto R$ {{ \App\Support\DecimalFormatter::format($allocation['gross_allocated']) }}, taxa R$ {{ \App\Support\DecimalFormatter::format($allocation['fee_allocated']) }}</li>@endforeach</ul></details>
                        @endif
                    </article>
                @empty
                    <p class="text-sm text-stone-500">Nenhum pagamento pode ser convertido enquanto os mappings estiverem pendentes.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border bg-white p-5">
            <h2 class="text-xl font-bold">Movimentos de estoque previstos</h2>
            <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@forelse ($importPlan['movements'] as $movement)<div class="rounded-lg bg-stone-50 p-3 text-sm"><strong>{{ $movement['product_name'] }}</strong><span class="block text-red-700">{{ \App\Support\DecimalFormatter::format($movement['quantity_delta'], 2) }} un</span></div>@empty<p class="text-sm text-stone-500">Nenhum movimento previsto.</p>@endforelse</div>
        </section>

        <form method="POST" action="{{ route('pdv.staging.import', [$connection, $order]) }}" class="rounded-xl border bg-white p-5">
            @csrf
            <input type="hidden" name="confirmed" value="1">
            <h2 class="font-bold">Confirmação humana futura</h2>
            <p class="mt-1 text-sm text-stone-600">O backend revalidará mappings, taxas, saldo, valores, escopo e idempotência dentro da transação.</p>
            <button @disabled(! $importPlan['can_execute']) class="mt-4 rounded-lg bg-stone-900 px-5 py-3 font-bold text-white disabled:cursor-not-allowed disabled:bg-stone-300">Confirmar importação atômica</button>
            @if (! $importPlan['import_enabled'])<p class="mt-2 text-xs font-semibold text-red-700">Botão bloqueado pela feature flag PDV_IMPORT_ENABLED=false.</p>@endif
        </form>
    </div>
@endsection
