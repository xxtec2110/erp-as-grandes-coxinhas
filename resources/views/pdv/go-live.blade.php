@extends('layouts.app')
@section('title', 'Preparar GrandChef para operação')
@section('content')
    <div class="space-y-6">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('pdv.index') }}" class="text-sm font-bold text-amber-700">← Integrações</a>
                <p class="mt-3 text-xs font-bold uppercase tracking-widest text-amber-700">{{ $connection->location->name }} · Go-live controlado</p>
                <h1 class="text-3xl font-bold text-stone-950">Preparar GrandChef para operação</h1>
                <p class="mt-2 max-w-3xl text-sm text-stone-600">Checklist derivado do staging oficial. O GrandChef fornece vendas, nunca estoque. Esta tela não consulta o GrandChef, não cria vendas e não altera saldos.</p>
            </div>
            <form method="GET" class="grid gap-2 rounded-xl border bg-white p-3 sm:grid-cols-[1fr_1fr_auto]">
                <label class="text-xs font-bold text-stone-600">De<input name="from" type="date" value="{{ $from }}" class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
                <label class="text-xs font-bold text-stone-600">Até<input name="to" type="date" value="{{ $to }}" class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
                <button class="self-end rounded-lg bg-stone-900 px-4 py-2 text-sm font-bold text-white">Atualizar</button>
            </form>
        </header>

        <section @class(['rounded-xl border p-5', 'border-red-300 bg-red-50' => !$goLive['import_enabled'], 'border-emerald-300 bg-emerald-50' => $goLive['import_enabled']])>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="font-bold">PDV_IMPORT_ENABLED={{ $goLive['import_enabled'] ? 'true' : 'false' }}</h2><p class="mt-1 text-sm">{{ $goLive['import_enabled'] ? 'A execução ainda exige todos os gates e confirmação pedido a pedido.' : 'Importação real permanece desabilitada, como exigido para esta preparação.' }}</p></div>
                <span @class(['rounded-full px-3 py-1 text-xs font-bold', 'bg-emerald-700 text-white' => $goLive['can_enable_import'], 'bg-red-700 text-white' => !$goLive['can_enable_import']])>can_enable_import={{ $goLive['can_enable_import'] ? 'true' : 'false' }}</span>
            </div>
        </section>

        <section class="rounded-xl border border-sky-200 bg-sky-50 p-5">
            <div class="grid gap-5 lg:grid-cols-[1fr_1.2fr] lg:items-start">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-sky-800">Marco de início oficial</p>
                    <h2 class="mt-1 text-xl font-bold text-sky-950">{{ $goLive['operational_start_set'] ? 'DEFINIDO' : 'NÃO DEFINIDO' }}</h2>
                    @if ($goLive['operational_start_set'])
                        <p class="mt-2 text-sm text-sky-900"><strong>{{ $goLive['operational_start_at']->format('d/m/Y H:i') }}</strong> · {{ config('app.timezone') }}</p>
                    @else
                        <p class="mt-2 text-sm text-sky-900">A importação operacional permanece bloqueada até uma decisão humana explícita.</p>
                    @endif
                    <p class="mt-3 text-sm text-sky-950">Apenas vendas concluídas a partir deste momento poderão afetar vendas e estoque do ERP.</p>
                    <p class="mt-2 text-xs text-sky-800">Definir ou ajustar o marco não cria venda, não consulta o GrandChef e não cria movimento de estoque.</p>
                </div>
                <form method="POST" action="{{ route('pdv.go-live.operational-start.update', $connection) }}" class="grid gap-3 rounded-xl border border-sky-200 bg-white p-4 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label class="text-xs font-bold text-stone-600">Data
                        <input type="date" name="operational_start_date" value="{{ old('operational_start_date', $goLive['operational_start_at']?->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border-stone-300 text-sm" required>
                    </label>
                    <label class="text-xs font-bold text-stone-600">Hora
                        <input type="time" name="operational_start_time" value="{{ old('operational_start_time', $goLive['operational_start_at']?->format('H:i')) }}" class="mt-1 w-full rounded-lg border-stone-300 text-sm" required>
                    </label>
                    <label class="flex gap-2 text-sm font-semibold sm:col-span-2"><input type="checkbox" name="confirmed" value="1" class="mt-1 rounded border-stone-300" required> Confirmo conscientemente a data e hora do início oficial desta unidade.</label>
                    <button class="rounded-lg bg-sky-900 px-4 py-2 text-sm font-bold text-white sm:col-span-2">{{ $goLive['operational_start_set'] ? 'Atualizar marco auditável' : 'Definir marco auditável' }}</button>
                </form>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-8">
            @foreach ($goLive['steps'] as $step)
                <article @class(['rounded-xl border p-4', 'border-emerald-200 bg-emerald-50' => $step['status'] === 'ready', 'border-amber-200 bg-amber-50' => $step['status'] === 'pending', 'border-red-200 bg-red-50' => $step['status'] === 'blocked'])>
                    <p class="text-xs font-bold uppercase text-stone-500">Etapa {{ $step['number'] }}</p>
                    <h2 class="mt-1 font-bold">{{ $step['label'] }}</h2>
                    <p class="mt-2 text-xs text-stone-600">{{ $step['detail'] }}</p>
                </article>
            @endforeach
        </section>

        <section class="rounded-xl border bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-xs font-bold uppercase text-amber-700">Etapa 1</p><h2 class="text-xl font-bold">Products oficiais faltantes</h2><p class="mt-1 text-sm text-stone-600">{{ $goLive['catalog']['summary']['products_distinct'] }} externos: {{ $goLive['catalog']['summary']['products_exact'] }} candidatos exatos e {{ $goLive['catalog']['summary']['products_without_candidate'] }} sem Product oficial.</p></div>
                <a href="{{ route('product-categories.index') }}" class="rounded-lg border px-3 py-2 text-sm font-bold">Gerenciar categorias oficiais</a>
            </div>
            @if ($goLive['category_blocked'] > 0)
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900"><strong>Gate de categoria:</strong> {{ $goLive['category_blocked'] }} bebida(s) aguardam uma categoria oficial de bebidas. Nenhuma categoria será criada automaticamente.</div>
            @endif
            @if ($goLive['missing_products']->isNotEmpty())
                @if ($canCreateProducts)
                    <form method="POST" action="{{ route('pdv.go-live.products.preview', $connection) }}" class="mt-5 space-y-3">
                        @csrf
                        <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                        <p class="text-xs font-bold uppercase text-stone-500">Nenhuma linha vem selecionada. Revise nome, categoria e preço antes da prévia.</p>
                        @foreach ($goLive['missing_products'] as $row)
                            <article class="grid gap-3 rounded-xl border p-4 lg:grid-cols-[auto_1.4fr_1fr_1fr_auto] lg:items-end">
                                <label class="flex items-center gap-2 self-center text-sm font-bold"><input type="checkbox" name="rows[{{ $loop->index }}][selected]" value="1" class="rounded border-stone-300"> Incluir</label>
                                <input type="hidden" name="rows[{{ $loop->index }}][external_product_id]" value="{{ $row['external_product_id'] }}">
                                <label class="text-xs font-bold text-stone-600">Nome oficial<input name="rows[{{ $loop->index }}][name]" value="{{ $row['suggested_name'] }}" class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
                                <label class="text-xs font-bold text-stone-600">Categoria<select name="rows[{{ $loop->index }}][product_category_id]" class="mt-1 w-full rounded-lg border-stone-300 text-sm"><option value="">Selecione</option>@foreach ($goLive['categories'] as $category)<option value="{{ $category->id }}" @selected($row['suggested_category']?->id === $category->id)>{{ $category->name }}</option>@endforeach</select></label>
                                <label class="text-xs font-bold text-stone-600">Preço de venda<input name="rows[{{ $loop->index }}][selling_price]" value="{{ $row['prices']['latest'] }}" inputmode="decimal" class="mt-1 w-full rounded-lg border-stone-300 text-sm"></label>
                                <div class="space-y-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="rows[{{ $loop->index }}][active]" value="1" checked class="rounded border-stone-300"> Ativo</label><p class="text-xs text-stone-500">{{ $row['quantity_total'] }} un · R$ {{ \App\Support\DecimalFormatter::format($row['value_total']) }} · {{ $row['order_count'] }} pedido(s)</p></div>
                                <div class="lg:col-start-2 lg:col-span-4 text-xs text-stone-500"><strong>Externo:</strong> {{ $row['description'] }} · preços observados: {{ collect($row['prices']['observed'])->map(fn ($price) => 'R$ '.\App\Support\DecimalFormatter::format($price))->join(', ') ?: 'não informado' }}</div>
                            </article>
                        @endforeach
                        <button class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-bold text-white">Gerar prévia dos Products selecionados</button>
                    </form>
                @else
                    <p class="mt-4 rounded-lg bg-stone-100 p-4 text-sm">Seu usuário pode conferir, mas não possui a permissão <code>products.create</code>.</p>
                @endif
            @else
                <p class="mt-4 rounded-lg bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">Não há Products oficiais pendentes neste período.</p>
            @endif
        </section>

        <section class="grid gap-5 xl:grid-cols-2">
            <article class="rounded-xl border bg-white p-5">
                <p class="text-xs font-bold uppercase text-amber-700">Etapa 2</p><h2 class="text-xl font-bold">Fila de mapping de alta confiança</h2>
                <p class="mt-1 text-sm text-stone-600">Sugestões não são confirmações e nenhuma vem pré-marcada.</p>
                <div class="mt-4 space-y-2">@forelse ($goLive['high_confidence'] as $row)<div class="flex gap-3 rounded-lg border p-3 text-sm"><input type="checkbox" disabled class="mt-1 rounded"><div><strong>{{ $row['description'] }}</strong><span class="block text-stone-600">Sugestão {{ $row['suggestion']['type'] }}: {{ $row['suggestion']['product']->name }}</span><span class="block text-xs text-stone-500">Impacto: {{ $row['quantity_total'] }} un · R$ {{ \App\Support\DecimalFormatter::format($row['value_total']) }} · {{ $row['order_count'] }} pedido(s)</span></div></div>@empty<p class="text-sm text-stone-500">Nenhum candidato de alta confiança pendente.</p>@endforelse</div>
                <a href="{{ route('pdv.mappings', [$connection, 'from' => $from, 'to' => $to, 'status' => 'unmapped']) }}" class="mt-4 inline-flex rounded-lg border px-3 py-2 text-sm font-bold">Revisar mappings manualmente</a>
            </article>

            <article class="rounded-xl border bg-white p-5">
                <p class="text-xs font-bold uppercase text-amber-700">Etapas 3 e 4</p><h2 class="text-xl font-bold">Pagamentos e taxas</h2>
                <div class="mt-4 space-y-2">@foreach ($goLive['catalog']['payments'] as $row)<div class="rounded-lg border p-3 text-sm"><div class="flex justify-between gap-2"><strong>{{ $row['external_form_description'] ?? $row['external_form_id'] }}</strong><span>{{ $row['occurrence_count'] }} ocorrência(s) · R$ {{ \App\Support\DecimalFormatter::format($row['amount_total']) }}</span></div><p class="mt-1 text-xs text-stone-600">{{ $row['mapping_status'] === 'confirmed' ? 'Mapping confirmado: '.$row['mapping']->payment_method : 'Mapping pendente' }} · {{ $row['compatibility']['supported'] ? 'compatível' : 'não suportado' }}@if ($row['configuration_missing']) · configuração financeira ausente @endif @if ($row['rate_missing']) · taxa vigente ausente @endif</p>@if ($row['financial_options']->isNotEmpty())<p class="mt-2 text-xs text-stone-500">Opções já cadastradas: {{ $row['financial_options']->map(fn ($fee) => $fee->acquirer->name.' / '.$fee->cardBrand->name)->join(', ') }}</p>@endif</div>@endforeach</div>
                <a href="{{ route('payment-fees.index') }}" class="mt-4 inline-flex rounded-lg border px-3 py-2 text-sm font-bold">Abrir central de taxas</a>
            </article>
        </section>

        <section class="grid gap-5 xl:grid-cols-2">
            <article class="rounded-xl border bg-white p-5">
                <p class="text-xs font-bold uppercase text-amber-700">Etapa 6</p><h2 class="text-xl font-bold">Estoque inicial oficial</h2>
                <p class="mt-1 text-sm text-stone-600">O estoque do GrandChef não será importado. Informe a quantidade física existente nesta unidade pelo fluxo oficial.</p>
                @if (! $goLive['operational_start_set'])
                    <p class="mt-3 rounded-lg bg-sky-50 p-3 text-xs font-semibold text-sky-900">Os dados staged permanecem como validação/pré-operação. Enquanto o marco estiver pendente, não geram necessidade operacional de estoque.</p>
                @endif
                <div class="mt-4 max-h-[34rem] space-y-2 overflow-y-auto">@forelse ($goLive['stock_inventory'] as $row)<div class="rounded-lg border p-3 text-sm"><div class="flex flex-wrap justify-between gap-2"><strong>{{ $row['product']->name }}</strong><span class="text-xs font-bold {{ $row['opening_stock_recorded'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $row['opening_stock_recorded'] ? 'Estoque inicial informado' : 'Estoque inicial pendente' }}</span></div><span class="mt-1 block">Saldo oficial {{ \App\Support\DecimalFormatter::format($row['balance'], 2) }} · necessidade operacional {{ \App\Support\DecimalFormatter::format($row['operational_required'], 2) }}</span><span class="block text-xs text-stone-500">Histórico/pré-operação {{ \App\Support\DecimalFormatter::format($row['historical_quantity'], 2) }} · última movimentação {{ $row['last_movement']?->operation_date?->format('d/m/Y') ?? 'nenhuma' }}</span></div>@empty<p class="rounded-lg bg-stone-100 p-3 text-sm">Nenhum Product mapeado para conferir.</p>@endforelse</div>
                <a href="{{ route('stock.opening.create', ['location_id' => $connection->location_id]) }}" class="mt-4 inline-flex rounded-lg border px-3 py-2 text-sm font-bold">Fluxo oficial de estoque inicial</a>
            </article>

            <article class="rounded-xl border bg-white p-5">
                <p class="text-xs font-bold uppercase text-amber-700">Etapa 7</p><h2 class="text-xl font-bold">Dry-run oficial de {{ $goLive['dry_run_summary']['orders'] }} pedidos</h2>
                <p class="mt-2 rounded-lg bg-stone-100 p-3 text-xs text-stone-700">Operacionais: {{ $goLive['dry_run_summary']['operational'] }} · históricos/pré-operação: {{ $goLive['dry_run_summary']['pre_operational'] + $goLive['dry_run_summary']['operational_start_pending'] }}. Histórico permanece consultável, mas não importável.</p>
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3"><div><dt class="text-stone-500">READY</dt><dd class="text-2xl font-bold">{{ $goLive['dry_run_summary']['ready'] }}</dd></div><div><dt class="text-stone-500">Bloqueados</dt><dd class="text-2xl font-bold">{{ $goLive['dry_run_summary']['blocked'] }}</dd></div><div><dt class="text-stone-500">ProductSales</dt><dd class="text-2xl font-bold">{{ $goLive['dry_run_summary']['planned_items'] }}</dd></div><div><dt class="text-stone-500">Pagamentos</dt><dd class="text-2xl font-bold">{{ $goLive['dry_run_summary']['planned_payments'] }}</dd></div><div><dt class="text-stone-500">Movimentos</dt><dd class="text-2xl font-bold">{{ $goLive['dry_run_summary']['planned_movements'] }}</dd></div></dl>
                <div class="mt-4 flex flex-wrap gap-2">@foreach ($goLive['dry_run_summary']['blocker_codes'] as $code => $count)<span class="rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-800">{{ $code }}: {{ $count }}</span>@endforeach</div>
                <details class="mt-4"><summary class="cursor-pointer text-sm font-bold">Ver os {{ $goLive['dry_runs']->count() }} resultados individuais</summary><div class="mt-3 max-h-80 space-y-2 overflow-y-auto">@foreach ($goLive['dry_runs'] as $run)<a href="{{ route('pdv.staging.show', [$connection, $run['order']]) }}" class="flex flex-wrap justify-between gap-2 rounded-lg border bg-stone-50 p-3 text-sm"><span class="font-bold">{{ $run['order']->external_code ?? $run['order']->external_order_id }} · R$ {{ \App\Support\DecimalFormatter::format($run['total']) }}</span><span>{{ $run['ready'] ? 'Pronto para importar' : 'Bloqueado' }} · {{ count($run['blockers']) }} motivo(s) · itens {{ $run['external_items'] }}/{{ $run['items'] }} previstos · pagamentos {{ $run['external_payments'] }}/{{ $run['payments'] }} previstos</span></a>@endforeach</div></details>
                <a href="{{ route('pdv.staging.index', [$connection, 'from' => $from, 'to' => $to]) }}" class="mt-4 inline-flex rounded-lg border px-3 py-2 text-sm font-bold">Abrir pedidos preparados</a>
            </article>
        </section>

        <section class="grid gap-5 xl:grid-cols-2">
            <article class="rounded-xl border border-red-200 bg-red-50 p-5"><h2 class="text-xl font-bold text-red-950">Gates que ainda bloqueiam</h2><ul class="mt-3 space-y-2 text-sm text-red-900">@forelse ($goLive['gate_reasons'] as $reason)<li><strong>{{ $reason['code'] }}</strong> — {{ $reason['message'] }}</li>@empty<li>Nenhum gate técnico pendente. A flag e a decisão humana continuam separadas.</li>@endforelse</ul></article>
            <article class="rounded-xl border bg-white p-5"><h2 class="text-xl font-bold">Checklist de aceitação</h2><ul class="mt-3 space-y-2 text-sm">@foreach ($goLive['checklist'] as $item)<li class="flex gap-2"><span>{{ $item['derived'] === true ? '✓' : ($item['derived'] === false ? '○' : '?') }}</span><span>{{ $item['label'] }}@if ($item['derived'] === null)<small class="block text-stone-500">Confirmação humana; o sistema não presume backup.</small>@endif</span></li>@endforeach</ul><p class="mt-4 rounded-lg bg-stone-100 p-3 text-sm font-semibold">Primeiro go-live: um único pedido por operação, com checkbox e texto explícito “IMPORTAR”.</p></article>
        </section>
    </div>
@endsection
