@extends('layouts.app')
@section('title', 'Acesso WhatsApp')
@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Acesso conversacional pelo WhatsApp</h1>
        <p class="page-subtitle">A identidade reconhece a pessoa. Cargos, permissões e unidades continuam vindo do usuário oficial do ERP.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a class="btn-secondary" href="{{ route('agent.observability') }}">Observabilidade</a>
        @if($canManage)<a class="btn-primary" href="{{ route('agent.identities.create') }}">Autorizar telefone</a>@endif
    </div>
</div>

<div class="mb-6 grid gap-3 sm:grid-cols-3">
    <div class="form-card"><p class="text-xs text-stone-500">Acessos ativos</p><p class="text-2xl font-bold">{{ $activeCount }}</p></div>
    <div class="form-card"><p class="text-xs text-stone-500">Identidades inativas</p><p class="text-2xl font-bold">{{ $inactiveCount }}</p></div>
    <div class="form-card"><p class="text-xs text-stone-500">Mensagens bloqueadas</p><p class="text-2xl font-bold">{{ $blockedCount }}</p></div>
</div>

<form method="GET" action="{{ route('agent.identities.index') }}" class="form-card mb-5 flex flex-col gap-3 sm:flex-row sm:items-end">
    <label class="min-w-0 flex-1"><span class="form-label">Buscar por nome ou telefone</span><input class="form-input" type="search" name="q" value="{{ $search }}" placeholder="Nome da pessoa, usuário ou telefone"></label>
    <div class="flex gap-2"><button class="btn-primary">Buscar</button>@if($search !== '')<a class="btn-secondary" href="{{ route('agent.identities.index') }}">Limpar</a>@endif</div>
</form>

<div class="space-y-3 md:hidden">
    @forelse($identities as $identity)
        <article class="form-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0"><p class="truncate font-bold">{{ $identity->display_name ?: ($identity->user?->name ?? 'Usuário removido') }}</p><p class="truncate text-sm text-stone-600">{{ $identity->user?->name ?? 'Sem usuário' }} · {{ $phones->mask($identity->phone_normalized) }}</p></div>
                <span class="status-badge {{ $identity->active ? 'status-active' : 'status-inactive' }}">{{ $identity->active ? 'Ativo' : 'Inativo' }}</span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-stone-500">Canal</dt><dd>WhatsApp</dd></div><div><dt class="text-stone-500">Responder</dt><dd>{{ $identity->respond_enabled ? 'Sim' : 'Não' }}</dd></div>
                <div class="col-span-2"><dt class="text-stone-500">Unidades</dt><dd>{{ $identity->user?->all_locations_access ? 'Todas' : ($identity->user?->locations?->pluck('name')->join(', ') ?: 'Sem unidade') }}</dd></div>
            </dl>
            <a class="text-link mt-3 inline-block" href="{{ route('agent.identities.edit', $identity) }}">Ver acesso</a>
        </article>
    @empty
        <div class="form-card empty-state">Nenhuma identidade encontrada.</div>
    @endforelse
</div>

<div class="table-card hidden md:block"><div class="overflow-x-auto"><table class="data-table">
    <thead><tr><th>Nome</th><th>Telefone</th><th>Usuário ERP</th><th>Canal</th><th>Status</th><th>Responder</th><th>Última atividade</th><th></th></tr></thead>
    <tbody>@forelse($identities as $identity)<tr>
        <td class="font-semibold">{{ $identity->display_name ?: '—' }}</td><td>{{ $phones->mask($identity->phone_normalized) }}</td><td>{{ $identity->user?->name ?? 'Usuário removido' }}</td><td>WhatsApp</td>
        <td><span class="status-badge {{ $identity->active ? 'status-active' : 'status-inactive' }}">{{ $identity->active ? 'Ativo' : 'Inativo' }}</span></td><td>{{ $identity->respond_enabled ? 'Sim' : 'Não' }}</td><td>{{ $identity->last_authorized_inbound_at?->format('d/m/Y H:i') ?? '—' }}</td>
        <td><a class="text-link" href="{{ route('agent.identities.edit', $identity) }}">Gerenciar</a></td>
    </tr>@empty<tr><td colspan="8" class="empty-state">Nenhuma identidade encontrada.</td></tr>@endforelse</tbody>
</table></div></div>
<div class="mt-5">{{ $identities->links() }}</div>
@endsection
