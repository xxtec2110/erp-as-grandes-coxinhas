@extends('layouts.app')
@section('title', 'Conferência de pedidos PDV')
@section('content')
    @php($summary = $result['summary'])
    <div class="space-y-6">
        <div>
            <a class="text-sm font-bold text-amber-700" href="{{ route('pdv.index') }}">← Integrações</a>
            <p class="mt-3 text-sm font-semibold uppercase tracking-wider text-amber-700">{{ $connection->location->name }}</p>
            <h1 class="text-3xl font-bold">Conferência antes da importação</h1>
            <p class="mt-2 max-w-3xl text-sm text-stone-600">Preparar não registra venda e não baixa estoque. Os pedidos permanecem em staging até uma futura confirmação operacional.</p>
        </div>

        <a class="inline-flex rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-bold text-amber-900" href="{{ route('pdv.mappings', [$connection, 'from' => $from, 'to' => $to]) }}">Revisar mappings e readiness</a>

        <form method="POST" action="{{ route('pdv.staging.prepare', $connection) }}" class="grid gap-4 rounded-xl border border-amber-200 bg-amber-50 p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            @csrf
            <label class="text-sm font-semibold">De
                <input class="mt-1 w-full rounded-lg border-stone-300" type="date" name="from" value="{{ old('from', $from) }}" required>
            </label>
            <label class="text-sm font-semibold">Até
                <input class="mt-1 w-full rounded-lg border-stone-300" type="date" name="to" value="{{ old('to', $to) }}" required>
            </label>
            <button class="rounded-lg bg-stone-900 px-5 py-3 font-bold text-white">Preparar para conferência</button>
            <p class="text-xs text-amber-900 sm:col-span-3">Período obrigatório, limitado a 7 dias. A ação é manual e idempotente.</p>
        </form>

        <form method="GET" action="{{ route('pdv.staging.index', $connection) }}" class="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-4">
            <label class="text-sm font-semibold">De<input class="ml-2 rounded-lg border-stone-300" type="date" name="from" value="{{ $from }}"></label>
            <label class="text-sm font-semibold">Até<input class="ml-2 rounded-lg border-stone-300" type="date" name="to" value="{{ $to }}"></label>
            <button class="rounded-lg border px-4 py-2 font-bold">Filtrar staging</button>
        </form>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach ([['Preparados', $summary['staged']], ['READY', $summary['ready']], ['Bloqueados', $summary['blocked']], ['Split payments', $summary['split_payments']], ['Total staged', 'R$ '.\App\Support\DecimalFormatter::format($summary['total'])]] as [$label, $value])
                <div class="rounded-xl border bg-white p-4"><p class="text-xs font-bold uppercase tracking-wider text-stone-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold">{{ $value }}</p></div>
            @endforeach
        </div>

        @if ($summary['operational_start_pending'] > 0 || $summary['pre_operational'] > 0)
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950"><strong>Visibilidade histórica preservada:</strong> {{ $summary['operational_start_pending'] }} pedido(s) aguardam definição do marco e {{ $summary['pre_operational'] }} estão antes do início oficial. Nenhum deles pode produzir efeitos operacionais.</div>
        @endif

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-stone-100 p-3 text-sm"><strong>{{ $summary['product_mapping_pending'] }}</strong> com produto pendente</div>
            <div class="rounded-lg bg-stone-100 p-3 text-sm"><strong>{{ $summary['payment_mapping_pending'] }}</strong> com pagamento pendente</div>
            <div class="rounded-lg bg-stone-100 p-3 text-sm"><strong>{{ $summary['stock_insufficient'] }}</strong> com estoque insuficiente</div>
            <div class="rounded-lg bg-stone-100 p-3 text-sm"><strong>{{ $summary['value_mismatch'] }}</strong> com divergência de valores</div>
        </div>

        <div class="overflow-x-auto rounded-xl border bg-white shadow-sm">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead class="bg-stone-50"><tr><th class="p-3">Pedido</th><th class="p-3">Conclusão</th><th class="p-3">Estado externo</th><th class="p-3 text-right">Itens</th><th class="p-3 text-right">Pagamentos</th><th class="p-3 text-right">Total</th><th class="p-3">Conferência</th><th class="p-3"></th></tr></thead>
                <tbody>
                    @forelse ($result['orders'] as $preview)
                        @php($order = $preview['order'])
                        @php($reconciliation = $preview['reconciliation'])
                        @php($classification = $reconciliation['operational_cutoff']['classification'])
                        <tr class="border-t align-top">
                            <td class="p-3"><span class="block font-mono text-xs">{{ $order->external_order_id }}</span><span class="text-stone-500">{{ $order->external_code ?? '—' }}</span></td>
                            <td class="p-3">{{ $order->external_completed_at?->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '—' }}</td>
                            <td class="p-3">{{ $order->external_status }}</td>
                            <td class="p-3 text-right">{{ $order->items->where('present_in_latest', true)->count() }}</td>
                            <td class="p-3 text-right">{{ $order->payments->where('present_in_latest', true)->count() }}</td>
                            <td class="p-3 text-right font-bold">R$ {{ \App\Support\DecimalFormatter::format($order->total) }}</td>
                            <td class="p-3">
                                <span @class(['rounded-full px-2 py-1 text-xs font-bold', 'bg-emerald-100 text-emerald-800' => $reconciliation['ready_for_import'], 'bg-sky-100 text-sky-800' => in_array($classification, ['pre_operational', 'operational_start_pending'], true), 'bg-red-100 text-red-800' => ! $reconciliation['ready_for_import'] && ! in_array($classification, ['pre_operational', 'operational_start_pending'], true)])>{{ match ($classification) { 'pre_operational' => 'HISTÓRICO / PRÉ-OPERAÇÃO', 'operational_start_pending' => 'PRÉ-OPERAÇÃO · MARCO PENDENTE', default => ($reconciliation['ready_for_import'] ? 'READY' : 'BLOQUEADO') } }}</span>
                                @if (! $reconciliation['ready_for_import'])<p class="mt-2 max-w-sm text-xs text-red-800">{{ collect($reconciliation['blockers'])->pluck('message')->implode(' ') }}</p>@endif
                            </td>
                            <td class="p-3"><a class="font-bold text-amber-700" href="{{ route('pdv.staging.show', [$connection, $order]) }}">Detalhar</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-8 text-center text-stone-500">Nenhum pedido preparado neste período.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
