@extends('layouts.app')
@section('title', 'Integrações GrandChef')
@section('content')
    <div class="space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-wider text-amber-700">Configurações · Integrações</p>
            <h1 class="text-3xl font-bold">GrandChef por unidade</h1>
            <p class="mt-2 max-w-3xl text-sm text-stone-600">Cada loja utiliza endpoint e credencial próprios. Consultas e relatórios desta área são somente leitura e não movimentam estoque.</p>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            @foreach ($locations as $location)
                @php($connection = $location->pdvConnections->first())
                <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-stone-500">{{ $location->typeLabel() }}</p>
                            <h2 class="text-xl font-bold">{{ $location->name }}</h2>
                        </div>
                        @if ($connection)
                            <span @class([
                                'rounded-full px-3 py-1 text-xs font-bold',
                                'bg-emerald-100 text-emerald-800' => $connection->status === 'healthy',
                                'bg-amber-100 text-amber-900' => in_array($connection->status, ['configured', 'not_configured'], true),
                                'bg-red-100 text-red-800' => in_array($connection->status, ['degraded', 'offline'], true),
                            ])>{{ str_replace('_', ' ', $connection->status) }}</span>
                        @else
                            <span class="rounded-full bg-stone-100 px-3 py-1 text-xs font-bold text-stone-600">Não configurado</span>
                        @endif
                    </div>

                    @if ($connection)
                        <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt class="text-stone-500">Integração</dt><dd class="font-semibold">{{ $connection->name }}</dd></div>
                            <div><dt class="text-stone-500">Modo</dt><dd class="font-semibold">{{ $connection->enabled ? 'Ativo' : 'Inativo' }}</dd></div>
                            <div><dt class="text-stone-500">Credencial</dt><dd class="font-semibold">{{ $credentialConfigured->get($connection->id) ? 'Configurada' : 'Não configurada' }}</dd></div>
                            <div><dt class="text-stone-500">Última tentativa</dt><dd class="font-semibold">{{ $connection->last_attempt_at?->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '—' }}</dd></div>
                            <div><dt class="text-stone-500">Última conexão válida</dt><dd class="font-semibold">{{ $connection->last_success_at?->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '—' }}</dd></div>
                            <div><dt class="text-stone-500">Pendências / erros</dt><dd class="font-semibold">{{ $connection->pending_count }} / {{ $connection->failed_count }}</dd></div>
                        </dl>
                        @if ($connection->last_error_message)
                            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900">
                                <strong>{{ $connection->last_error_code }}:</strong> {{ $connection->last_error_message }}
                            </div>
                        @endif
                        <div class="mt-5 flex flex-wrap gap-2">
                            <a href="{{ route('pdv.go-live', $connection) }}" class="rounded-lg bg-amber-700 px-3 py-2 text-sm font-bold text-white">Preparar operação</a>
                            <a href="{{ route('pdv.connections.edit', $connection) }}" class="rounded-lg bg-stone-900 px-3 py-2 text-sm font-bold text-white">Editar</a>
                            <form method="POST" action="{{ route('pdv.test', $connection) }}">@csrf<button class="rounded-lg border px-3 py-2 text-sm font-bold">Testar conexão</button></form>
                            <a href="{{ route('pdv.reports.sales', $connection) }}" class="rounded-lg border px-3 py-2 text-sm font-bold">Consultar vendas</a>
                            <a href="{{ route('pdv.staging.index', $connection) }}" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-bold text-amber-900">Pedidos preparados</a>
                            <a href="{{ route('pdv.mappings', $connection) }}" class="rounded-lg border px-3 py-2 text-sm font-bold">Mapeamentos / Readiness</a>
                            <a href="{{ route('pdv.events', $connection) }}" class="rounded-lg border px-3 py-2 text-sm font-bold">Observabilidade</a>
                        </div>
                    @elseif ($location->type === \App\Models\Location::TYPE_STORE)
                        <p class="mt-5 text-sm text-stone-600">Esta loja pode receber uma conexão GrandChef independente.</p>
                        <a href="{{ route('pdv.connections.create', $location) }}" class="mt-4 inline-flex rounded-lg bg-stone-900 px-4 py-2 text-sm font-bold text-white">Configurar GrandChef</a>
                    @else
                        <p class="mt-5 text-sm text-stone-500">Unidade de produção sem integração GrandChef. Nenhuma conexão foi criada automaticamente.</p>
                    @endif
                </section>
            @endforeach
        </div>

        @if ($legacyConnections->isNotEmpty())
            <section class="rounded-xl border border-amber-300 bg-amber-50 p-5">
                <h2 class="text-lg font-bold text-amber-950">Conexões legadas sem unidade</h2>
                <p class="mt-1 text-sm text-amber-900">Foram preservadas e permanecem impedidas de ativar, testar ou sincronizar até que o Admin Master as vincule explicitamente.</p>
                <div class="mt-4 space-y-2">
                    @foreach ($legacyConnections as $connection)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-white p-3">
                            <span class="font-semibold">{{ $connection->name }} · {{ $connection->status }}</span>
                            <a class="text-sm font-bold text-amber-800" href="{{ route('pdv.connections.edit', $connection) }}">Vincular a uma unidade</a>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
