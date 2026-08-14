@inject('authorization', 'App\Services\AuthorizationService')
@inject('whatsappConnection', 'App\Services\WhatsAppConnectionService')
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@yield('title', 'Administração') — {{ config('app.name', 'As Grandes Coxinhas') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-stone-100 font-sans text-stone-900 antialiased">
        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-4 py-4">
                    <a href="{{ route('dashboard') }}" class="shrink-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-700">ERP</p>
                        <p class="text-lg font-bold">As Grandes Coxinhas</p>
                    </a>

                    <div class="flex items-center gap-2">
                        <details class="group relative">
                            <summary @class([
                                'flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold transition marker:hidden sm:px-4',
                                'border-amber-300 bg-amber-50 text-amber-900' => request()->routeIs(['ingredient-categories.*', 'product-categories.*', 'users.*', 'agent.*', 'pdv.*', 'suppliers.*', 'equipment.*', 'glp-products.*', 'loss-reasons.*']),
                                'border-stone-300 bg-white text-stone-700 hover:bg-stone-50' => ! request()->routeIs(['ingredient-categories.*', 'product-categories.*', 'users.*', 'agent.*', 'pdv.*', 'suppliers.*', 'equipment.*', 'glp-products.*', 'loss-reasons.*']),
                            ])>
                                Configurações
                                <span aria-hidden="true" class="text-xs transition group-open:rotate-180">▼</span>
                            </summary>
                            <nav class="absolute right-0 z-20 mt-2 w-64 rounded-xl border border-stone-200 bg-white p-2 shadow-xl" aria-label="Configurações">
                                @if ($authorization->allows(auth()->user(), 'suppliers.view'))
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('ingredient-categories.index') }}">Categorias de insumos</a>
                                @endif
                                @if ($authorization->allows(auth()->user(), 'product_categories.manage'))
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('product-categories.index') }}">Categorias de produtos</a>
                                @endif
                                @if ($authorization->allows(auth()->user(), 'users.manage'))
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('users.index') }}">Usuários e acessos</a>
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('agent.identities.index') }}">Identidades externas</a>
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('agent.observability') }}">Observabilidade do Agente</a>
                                    @if ($authorization->allows(auth()->user(), 'agent.whatsapp.manage_connection'))
                                        <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('agent.whatsapp.index') }}">WhatsApp do Agente</a>
                                    @endif
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('agent.simulator') }}">Simulador do Agente</a>
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('agent.usage') }}">Uso do Agente</a>
                                    @if ($authorization->allows(auth()->user(), 'pdv.manage'))
                                        <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('pdv.index') }}">Integrações · PDV / GrandChef</a>
                                    @endif
                                @endif
                                @if ($authorization->allows(auth()->user(), 'payment_fees.view'))
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('payment-fees.index') }}">Taxas de Venda</a>
                                @endif
                                @if ($authorization->allows(auth()->user(), 'catalogs.manage'))
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('suppliers.index') }}">Fornecedores</a>
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('equipment.index') }}">Equipamentos</a>
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('glp-products.index') }}">GLP / Energia</a>
                                    <a class="block rounded-lg px-3 py-2.5 text-sm font-semibold text-stone-700 hover:bg-amber-50 hover:text-amber-900" href="{{ route('loss-reasons.index') }}">Motivos de perda</a>
                                @endif
                            </nav>
                        </details>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="min-h-10 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm font-semibold hover:bg-stone-50 focus:outline-none focus:ring-4 focus:ring-amber-100 sm:px-4">Sair</button>
                        </form>
                    </div>
                </div>

                <nav class="flex flex-wrap gap-1 border-t border-stone-100 py-3" aria-label="Navegação principal">
                    @foreach ([
                        ['route' => 'dashboard', 'label' => 'Dashboard', 'active' => 'dashboard'],
                        ['route' => 'ingredients.index', 'label' => 'Insumos', 'active' => 'ingredients.*', 'permission' => 'ingredients.view'],
                        ['route' => 'ingredient-stock.index', 'label' => 'Estoque de Insumos', 'active' => 'ingredient-stock.*', 'permission' => 'ingredient_stock.view'],
                        ['route' => 'preparations.index', 'label' => 'Preparo de Recheios', 'active' => 'preparations.*', 'permission' => 'preparations.view'],
                        ['label' => 'Montagem das Coxinhas'],
                        ['route' => 'production-orders.index', 'label' => 'Produção', 'active' => 'production-orders.*', 'permission' => 'production.orders.view'],
                        ['route' => 'products.index', 'label' => 'Produtos', 'active' => 'products.*', 'permission' => 'products.view'],
                        ['route' => 'stock.index', 'label' => 'Estoque', 'active' => 'stock.*', 'permission' => 'stock.view'],
                        ['route' => 'transfers.index', 'label' => 'Entradas / Recebimentos', 'active' => 'transfers.*', 'permission' => 'transfers.view'],
                        ['route' => 'sales.index', 'label' => 'Vendas', 'active' => 'sales.*', 'permission' => 'sales.view'],
                        ['route' => 'finance.index', 'label' => 'Financeiro', 'active' => 'finance.*', 'permission' => 'finance.view'],
                        ['route' => 'purchases.index', 'label' => 'Compras', 'active' => 'purchases.*', 'permission' => 'purchases.view'],
                        ['route' => 'losses.index', 'label' => 'Perdas', 'active' => 'losses.*', 'permission' => 'losses.view'],
                        ['route' => 'reports.operational', 'label' => 'Relatórios', 'active' => 'reports.*', 'permission' => 'reports.view'],
                        ['route' => 'locations.index', 'label' => 'Unidades', 'active' => 'locations.*', 'permission' => 'locations.view'],
                    ] as $item)
                        @if (isset($item['route']) && (! isset($item['permission']) || $authorization->allows(auth()->user(), $item['permission'])))
                            <a
                                href="{{ route($item['route']) }}"
                                @class([
                                    'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold transition',
                                    'bg-amber-100 text-amber-900' => request()->routeIs($item['active']),
                                    'text-stone-600 hover:bg-stone-100 hover:text-stone-900' => ! request()->routeIs($item['active']),
                                ])
                            >{{ $item['label'] }}</a>
                        @else
                            <span class="cursor-not-allowed whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-stone-400" title="Em desenvolvimento" aria-disabled="true">{{ $item['label'] }}</span>
                        @endif
                    @endforeach
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            @if(config('whatsapp.enabled') && $authorization->allows(auth()->user(), 'agent.whatsapp.manage_connection') && $whatsappConnection->current()->status === 'unavailable')
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
                    <div><strong>🔴 WhatsApp do Agente indisponível.</strong> As mensagens podem não estar sendo processadas.</div>
                    <a class="font-bold underline" href="{{ route('agent.whatsapp.index') }}">Ver detalhes</a>
                </div>
            @endif
            @if (session('success'))
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status">
                    {{ session('success') }}
                </div>
            @endif

            @yield('content')
        </main>
    </body>
</html>
