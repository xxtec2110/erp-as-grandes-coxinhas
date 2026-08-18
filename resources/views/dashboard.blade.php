@extends('layouts.app')
@section('title', 'Dashboard de Gestão')
@section('content')
<div class="overflow-hidden rounded-3xl bg-[#0c0d0f] text-white shadow-2xl ring-1 ring-black/10">
    <header class="border-b border-white/10 bg-gradient-to-r from-[#111318] to-[#17120b] px-4 py-6 sm:px-7 lg:px-9">
        <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
            <div class="flex items-center gap-4">
                <div class="grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-amber-300 to-orange-600 text-xl font-black text-stone-950 shadow-lg shadow-orange-950/40">AG</div>
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.28em] text-amber-400">As Grandes Coxinhas</p>
                    <h1 class="mt-1 text-2xl font-black tracking-tight sm:text-3xl">Dashboard de Gestão</h1>
                    <p class="mt-1 text-sm text-stone-400">{{ $location?->name ?? 'Nenhuma unidade disponível' }} · {{ $periodLabel }}</p>
                </div>
            </div>
            <form method="GET" action="{{ route('dashboard') }}" class="grid gap-3 rounded-2xl border border-white/10 bg-white/[0.04] p-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block"><span class="mb-1 block text-[10px] font-black uppercase tracking-wider text-stone-400">Unidade</span><select name="location_id" class="w-full rounded-xl border border-white/10 bg-stone-900 px-3 py-2 text-sm text-white"><option value="">Selecione</option>@foreach($locations as $item)<option value="{{ $item->id }}" @selected($location?->id === $item->id)>{{ $item->name }}</option>@endforeach</select></label>
                <label class="block"><span class="mb-1 block text-[10px] font-black uppercase tracking-wider text-stone-400">Período</span><select name="period" class="w-full rounded-xl border border-white/10 bg-stone-900 px-3 py-2 text-sm text-white" data-dashboard-period><option value="today" @selected(request('period', 'today') === 'today')>Hoje</option><option value="week" @selected(request('period') === 'week')>Semana</option><option value="fortnight" @selected(request('period') === 'fortnight')>Quinzena</option><option value="month" @selected(request('period') === 'month')>Mês</option><option value="custom" @selected(request('period') === 'custom')>Personalizado</option></select></label>
                <label class="block"><span class="mb-1 block text-[10px] font-black uppercase tracking-wider text-stone-400">Início</span><input name="start_date" type="date" value="{{ request('start_date', $startDate) }}" class="w-full rounded-xl border border-white/10 bg-stone-900 px-3 py-2 text-sm text-white"></label>
                <div class="flex items-end gap-2"><label class="min-w-0 flex-1"><span class="mb-1 block text-[10px] font-black uppercase tracking-wider text-stone-400">Fim</span><input name="end_date" type="date" value="{{ request('end_date', $endDate) }}" class="w-full rounded-xl border border-white/10 bg-stone-900 px-3 py-2 text-sm text-white"></label><button class="rounded-xl bg-amber-400 px-4 py-2 font-black text-stone-950 transition hover:bg-amber-300">Filtrar</button></div>
            </form>
        </div>
    </header>

    <main class="p-4 sm:p-7 lg:p-9">
        @if($location === null)
            <div class="rounded-2xl border border-amber-400/30 bg-amber-400/10 p-6 text-amber-100">Nenhuma unidade ativa está disponível para este usuário.</div>
        @elseif($widgets === [])
            <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center"><p class="text-lg font-black">Nenhum widget disponível</p><p class="mt-2 text-sm text-stone-400">As permissões e preferências atuais não liberam informações para este dashboard.</p></div>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach($widgets as $widget)
                    <div data-widget-key="{{ $widget['key'] }}" class="{{ $widget['size'] === 'wide' ? 'md:col-span-2 xl:col-span-4' : '' }}">
                        @include($widget['view'], ['widget' => $widget, 'data' => $widget['data']])
                    </div>
                @endforeach
            </div>
        @endif
    </main>
</div>
@endsection
