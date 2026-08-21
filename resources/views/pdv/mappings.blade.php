@extends('layouts.app')
@section('title', 'Mapeamentos GrandChef')
@section('content')
    @php($summary = $catalog['summary'])
    @php($readinessSummary = $readiness['summary'])
    <div class="space-y-6">
        <header>
            <a class="text-sm font-bold text-amber-700" href="{{ route('pdv.index') }}">← Integrações</a>
            <p class="mt-3 text-sm font-semibold uppercase tracking-wider text-amber-700">{{ $connection->location->name }}</p>
            <h1 class="text-3xl font-bold">Mapeamentos GrandChef</h1>
            <p class="mt-2 max-w-4xl text-sm text-stone-600">Sugestões são calculadas a partir do staging e nunca são salvas automaticamente. Confirmar mapping não importa venda e não movimenta estoque.</p>
        </header>

        <nav class="grid gap-2 rounded-xl border bg-white p-3 sm:grid-cols-3" aria-label="Seções de mapeamento">
            <a class="rounded-lg bg-stone-100 px-4 py-3 text-center text-sm font-bold hover:bg-stone-200" href="#produtos">Produtos</a>
            <a class="rounded-lg bg-stone-100 px-4 py-3 text-center text-sm font-bold hover:bg-stone-200" href="#pagamentos">Pagamentos</a>
            <a class="rounded-lg bg-stone-100 px-4 py-3 text-center text-sm font-bold hover:bg-stone-200" href="#readiness">Readiness</a>
        </nav>

        <form method="GET" action="{{ route('pdv.mappings', $connection) }}" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end">
            <label class="text-sm font-semibold">De<input class="mt-1 w-full rounded-lg border-stone-300" type="date" name="from" value="{{ $from }}" required></label>
            <label class="text-sm font-semibold">Até<input class="mt-1 w-full rounded-lg border-stone-300" type="date" name="to" value="{{ $to }}" required></label>
            <label class="text-sm font-semibold">Produtos<select class="mt-1 w-full rounded-lg border-stone-300" name="status"><option value="unmapped" @selected($status === 'unmapped')>Sem mapping</option><option value="all" @selected($status === 'all')>Todos</option></select></label>
            <button class="rounded-lg bg-stone-900 px-5 py-3 font-bold text-white">Atualizar painel</button>
        </form>

        <section id="produtos" class="scroll-mt-6 space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 class="text-2xl font-bold">Produtos externos</h2><p class="mt-1 text-sm text-stone-600">{{ $summary['products_distinct'] }} distintos no período · {{ $summary['products_mapped'] }} confirmados · {{ $summary['products_without_candidate'] }} sem candidato.</p></div>
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">Nenhuma seleção é confirmada ao abrir esta tela</span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([['Confirmados', $summary['products_mapped'], 'bg-emerald-50 text-emerald-900'], ['Sugestão exata', $summary['products_exact'], 'bg-amber-50 text-amber-900'], ['Via alias', $summary['products_alias'], 'bg-amber-50 text-amber-900'], ['Somente similar', $summary['products_similar'], 'bg-stone-100 text-stone-800'], ['Sem candidato', $summary['products_without_candidate'], 'bg-red-50 text-red-900']] as [$label, $value, $classes])
                    <div class="rounded-xl border p-4 {{ $classes }}"><p class="text-xs font-bold uppercase tracking-wider">{{ $label }}</p><p class="mt-1 text-2xl font-bold">{{ $value }}</p></div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('pdv.mappings.products.batch.preview', $connection) }}" class="space-y-3">
                @csrf
                <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                @forelse ($catalog['products'] as $index => $row)
                    @php($suggested = $row['suggestion']['product'])
                    @php($autoVisibleId = in_array($row['suggestion']['type'], ['exact', 'alias'], true) ? $suggested?->id : null)
                    @php($selectedId = old("rows.$index.product_id", $row['mapping']?->product_id ?? $autoVisibleId))
                    <article class="rounded-xl border bg-white p-4 shadow-sm">
                        <div class="grid gap-4 lg:grid-cols-[auto_1.4fr_1fr_1.2fr] lg:items-start">
                            <div class="pt-1">
                                <input type="hidden" name="rows[{{ $index }}][selected]" value="0">
                                <input class="h-5 w-5 rounded border-stone-300 text-amber-600" type="checkbox" name="rows[{{ $index }}][selected]" value="1" aria-label="Selecionar {{ $row['description'] }}">
                                <input type="hidden" name="rows[{{ $index }}][external_product_id]" value="{{ $row['external_product_id'] }}">
                            </div>
                            <div>
                                <div class="flex flex-wrap items-center gap-2"><h3 class="font-bold">{{ $row['description'] }}</h3><span @class(['rounded-full px-2 py-1 text-xs font-bold', 'bg-emerald-100 text-emerald-800' => $row['mapping_status'] === 'confirmed', 'bg-red-100 text-red-800' => $row['mapping_status'] !== 'confirmed'])>{{ $row['mapping_status'] === 'confirmed' ? 'Confirmado' : 'Sem mapping' }}</span></div>
                                <p class="mt-1 font-mono text-xs text-stone-500">Código {{ $row['external_product_code'] ?? '—' }} · ID {{ $row['external_product_id'] }}</p>
                                <p class="mt-2 text-sm text-stone-600">{{ \App\Support\DecimalFormatter::format($row['quantity_total'], 2) }} vendidos · {{ $row['order_count'] }} pedidos · R$ {{ \App\Support\DecimalFormatter::format($row['value_total']) }}</p>
                                <p class="mt-1 text-xs text-stone-500">Primeiro: {{ $row['first_appearance']->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }} · Último: {{ $row['last_appearance']->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                            </div>
                            <div class="rounded-lg bg-stone-50 p-3 text-sm">
                                <p class="text-xs font-bold uppercase tracking-wider text-stone-500">Sugestão não persistida</p>
                                @if ($suggested)
                                    <p class="mt-1 font-bold">{{ $suggested->name }}</p>
                                    <p class="text-xs text-stone-600">{{ match($row['suggestion']['type']) {'exact' => 'Exata', 'alias' => 'Alias oficial', 'similar' => 'Similar', default => 'Sem candidato'} }} · confiança {{ $row['suggestion']['confidence'] }}</p>
                                @else
                                    <p class="mt-1 font-bold text-red-800">Produto oficial ainda não cadastrado no ERP.</p>
                                @endif
                                <p class="mt-2 text-xs text-stone-500">Impacto: {{ $row['order_count'] }} pedido(s) deixam de ter este blocker se o mapping for confirmado.</p>
                            </div>
                            <div>
                                <label class="text-xs font-bold uppercase tracking-wider text-stone-500">Produto ERP escolhido
                                    <select class="mt-1 w-full rounded-lg border-stone-300" name="rows[{{ $index }}][product_id]">
                                        <option value="">Selecione explicitamente</option>
                                        @foreach ($erpProducts as $product)
                                            <option value="{{ $product->id }}" @selected((string) $selectedId === (string) $product->id)>{{ $product->name }} · {{ $product->category?->name ?? 'Sem categoria' }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                @if ($row['mapping_status'] === 'confirmed')
                                    <label class="mt-2 flex items-start gap-2 text-xs text-red-800"><input type="hidden" name="rows[{{ $index }}][confirm_remap]" value="0"><input class="mt-0.5 rounded" type="checkbox" name="rows[{{ $index }}][confirm_remap]" value="1">Confirmo que quero alterar o mapping já confirmado.</label>
                                @else
                                    <input type="hidden" name="rows[{{ $index }}][confirm_remap]" value="0">
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl border bg-white p-8 text-center text-stone-500">Nenhum produto externo corresponde ao filtro.</div>
                @endforelse
                @if ($catalog['products']->isNotEmpty())
                    <div class="sticky bottom-3 flex justify-end"><button class="rounded-lg bg-stone-900 px-5 py-3 font-bold text-white shadow-lg">Revisar mappings selecionados</button></div>
                @endif
            </form>

            @if ($catalog['missing_products']->isNotEmpty())
                <div class="rounded-xl border border-red-200 bg-red-50 p-5">
                    <h3 class="font-bold text-red-900">Produtos externos sem cadastro oficial</h3>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach ($catalog['missing_products'] as $row)
                            <div class="rounded-lg bg-white p-3 text-sm"><strong>{{ $row['description'] }}</strong><span class="block text-xs text-stone-500">Código {{ $row['external_product_code'] ?? '—' }} · {{ \App\Support\DecimalFormatter::format($row['quantity_total'], 2) }} vendidos · R$ {{ \App\Support\DecimalFormatter::format($row['value_total']) }} · {{ $row['order_count'] }} pedidos</span></div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <section id="pagamentos" class="scroll-mt-6 space-y-4">
            <div><h2 class="text-2xl font-bold">Pagamentos externos</h2><p class="mt-1 text-sm text-stone-600">{{ $summary['payments_distinct'] }} formas distintas · split payment preservado por pedido.</p></div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([['Confirmados', $summary['payments_mapped']], ['Sem mapping', $summary['payments_unmapped']], ['Incompatíveis', $summary['payments_unsupported']], ['Sem taxa vigente', $summary['payments_rate_missing']]] as [$label, $value])
                    <div class="rounded-xl border bg-white p-4"><p class="text-xs font-bold uppercase tracking-wider text-stone-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold">{{ $value }}</p></div>
                @endforeach
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($catalog['payments'] as $row)
                    <article class="rounded-xl border bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div><h3 class="font-bold">{{ $row['external_form_description'] ?? 'Forma não informada' }}</h3><p class="font-mono text-xs text-stone-500">{{ $row['external_type'] ?? 'sem tipo' }} · ID {{ $row['external_form_id'] }}</p></div>
                            <span @class(['rounded-full px-2 py-1 text-xs font-bold', 'bg-emerald-100 text-emerald-800' => $row['mapping_status'] === 'confirmed', 'bg-amber-100 text-amber-900' => $row['compatibility']['supported'] && $row['mapping_status'] !== 'confirmed', 'bg-red-100 text-red-800' => ! $row['compatibility']['supported']])>{{ ! $row['compatibility']['supported'] ? 'Não suportado' : ($row['mapping_status'] === 'confirmed' ? 'Confirmado' : 'Sem mapping') }}</span>
                        </div>
                        <p class="mt-3 text-sm text-stone-600">{{ $row['occurrence_count'] }} ocorrência(s) · {{ $row['order_count'] }} pedidos · R$ {{ \App\Support\DecimalFormatter::format($row['amount_total']) }}</p>
                        @if (! $row['compatibility']['supported'])
                            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900">{{ $row['compatibility']['reason'] }}</div>
                        @else
                            <form class="mt-4 space-y-3" method="POST" action="{{ route('pdv.mappings.payments.update', [$connection, $row['external_form_id']]) }}">
                                @csrf @method('PUT')
                                <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                                <input type="hidden" name="payment_method" value="{{ $row['compatibility']['method'] }}">
                                <p class="rounded-lg bg-stone-50 p-3 text-sm"><strong>Representação ERP:</strong> {{ $row['compatibility']['label'] }}<span class="block text-xs text-stone-500">{{ $row['compatibility']['requires_rate'] ? 'Exige adquirente, bandeira e taxa vigente.' : 'Não exige adquirente, bandeira ou taxa.' }}</span></p>
                                @if ($row['compatibility']['requires_acquirer'])
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="text-sm font-semibold">Adquirente<select class="mt-1 w-full rounded-lg border-stone-300" name="acquirer_id" required><option value="">Selecione</option>@foreach ($acquirers as $item)<option value="{{ $item->id }}" @selected($row['mapping']?->acquirer_id === $item->id)>{{ $item->name }}</option>@endforeach</select></label>
                                        <label class="text-sm font-semibold">Bandeira<select class="mt-1 w-full rounded-lg border-stone-300" name="card_brand_id" required><option value="">Selecione</option>@foreach ($cardBrands as $item)<option value="{{ $item->id }}" @selected($row['mapping']?->card_brand_id === $item->id)>{{ $item->name }}</option>@endforeach</select></label>
                                    </div>
                                @endif
                                @if ($row['mapping_status'] === 'confirmed')
                                    <label class="flex items-start gap-2 text-xs text-red-800"><input class="mt-0.5 rounded" type="checkbox" name="confirm_remap" value="1">Confirmo a alteração explícita deste mapping.</label>
                                @endif
                                <button class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-bold text-white">Confirmar mapping financeiro</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        <section id="readiness" class="scroll-mt-6 space-y-4">
            <div><h2 class="text-2xl font-bold">Readiness operacional</h2><p class="mt-1 text-sm text-stone-600">Cálculo em tempo real. Blockers não são persistidos e nenhum pedido pode ser importado parcialmente.</p></div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([['Staged', $readinessSummary['staged'], 'bg-white'], ['READY', $readinessSummary['ready'], 'bg-emerald-50'], ['Bloqueados', $readinessSummary['blocked'], 'bg-red-50'], ['Split payments', $readinessSummary['split_payments'], 'bg-white'], ['Total', 'R$ '.\App\Support\DecimalFormatter::format($readinessSummary['total']), 'bg-white']] as [$label, $value, $class])
                    <div class="rounded-xl border p-4 {{ $class }}"><p class="text-xs font-bold uppercase tracking-wider text-stone-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold">{{ $value }}</p></div>
                @endforeach
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-lg bg-red-50 p-3 text-sm"><strong>{{ $readinessSummary['product_mapping_pending'] }}</strong> pedidos com produto pendente</div>
                <div class="rounded-lg bg-red-50 p-3 text-sm"><strong>{{ $readinessSummary['payment_mapping_pending'] }}</strong> com pagamento pendente</div>
                <div class="rounded-lg bg-red-50 p-3 text-sm"><strong>{{ $readinessSummary['payment_unsupported'] }}</strong> com pagamento incompatível</div>
                <div class="rounded-lg bg-amber-50 p-3 text-sm"><strong>{{ $readinessSummary['payment_rate_missing'] }}</strong> sem taxa vigente</div>
                <div class="rounded-lg bg-red-50 p-3 text-sm"><strong>{{ $readinessSummary['stock_insufficient'] }}</strong> com estoque insuficiente</div>
            </div>

            <div class="rounded-xl border bg-white p-5">
                <h3 class="font-bold">Blockers calculados</h3>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($readinessSummary['blocker_codes'] as $code => $count)
                        <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-900">{{ $code }} · {{ $count }}</span>
                    @empty
                        <span class="text-sm text-stone-500">Nenhum blocker no período.</span>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                <h3 class="font-bold text-amber-950">Prévia de necessidade de estoque — não operacional</h3>
                <p class="mt-1 text-xs text-amber-900">Mappings confirmados usam o produto oficial; sugestões exatas/alias aparecem somente como simulação e não liberam pedidos.</p>
                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse ($catalog['stock_preview'] as $row)
                        <div class="rounded-lg bg-white p-3 text-sm"><strong>{{ $row['product']->name }}</strong><span class="block text-xs text-stone-600">{{ $row['source'] === 'mapping_confirmed' ? 'Mapping confirmado' : 'Sugestão em preview' }} · necessidade {{ \App\Support\DecimalFormatter::format($row['required'], 2) }} · saldo {{ \App\Support\DecimalFormatter::format($row['available'], 2) }} · déficit {{ \App\Support\DecimalFormatter::format($row['deficit'], 2) }}</span></div>
                    @empty
                        <p class="text-sm text-amber-900">Nenhuma necessidade pode ser associada com segurança.</p>
                    @endforelse
                </div>
            </div>

            <a class="inline-flex rounded-lg border px-4 py-2 text-sm font-bold" href="{{ route('pdv.staging.index', [$connection, 'from' => $from, 'to' => $to]) }}">Abrir os {{ $readinessSummary['staged'] }} pedidos staged</a>
        </section>
    </div>
@endsection
