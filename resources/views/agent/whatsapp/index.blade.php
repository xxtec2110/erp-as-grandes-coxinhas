@extends('layouts.app')
@section('title', 'Canais do Agente')
@section('content')
@php
    $statusClass = fn (string $status) => match ($status) {
        'READY' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'NOT CONFIGURED', 'PHONE NOT PROVISIONED', 'PUBLIC WEBHOOK REQUIRED', 'INCONCLUSIVE' => 'border-amber-200 bg-amber-50 text-amber-900',
        default => 'border-red-200 bg-red-50 text-red-900',
    };
    $statusIcon = fn (string $status) => $status === 'READY' ? '●' : ($status === 'ERROR' ? '×' : '▲');
@endphp

<div class="page-header">
    <div>
        <h1 class="page-title">Canais do Agente</h1>
        <p class="page-subtitle">Saúde técnica do Agent, OpenAI e canal oficial Meta WhatsApp Cloud API.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a class="btn-secondary" href="{{ route('agent.identities.index') }}">Identidades autorizadas</a>
        <a class="btn-secondary" href="{{ route('agent.observability') }}">Observabilidade e custos</a>
    </div>
</div>

<section class="mb-6 grid gap-4 lg:grid-cols-3">
    <article class="form-card border {{ $statusClass($health['agent']['status']) }}">
        <div class="flex items-center justify-between gap-3"><h2 class="section-title">Agent ERP</h2><span class="status-badge">{{ $statusIcon($health['agent']['status']) }} {{ $health['agent']['status'] }}</span></div>
        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-stone-500">Identity Gate</dt><dd class="font-semibold">{{ $health['agent']['identity_gate'] ? 'Ativo' : 'Erro' }}</dd></div>
            <div><dt class="text-stone-500">Identidades ativas</dt><dd class="font-semibold">{{ $health['agent']['authorized_identities'] }}</dd></div>
            <div><dt class="text-stone-500">Tools registradas</dt><dd class="font-semibold">{{ $health['agent']['tools'] }}</dd></div>
            <div><dt class="text-stone-500">Ações pendentes</dt><dd class="font-semibold">{{ $health['agent']['pending_actions'] }}</dd></div>
            <div class="col-span-2"><dt class="text-stone-500">Erros nas últimas 24h</dt><dd class="font-semibold">{{ $health['agent']['errors'] }}</dd></div>
        </dl>
    </article>

    <article class="form-card border {{ $statusClass($health['openai']['status']) }}">
        <div class="flex items-center justify-between gap-3"><h2 class="section-title">OpenAI</h2><span class="status-badge">{{ $statusIcon($health['openai']['status']) }} {{ $health['openai']['status'] }}</span></div>
        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-stone-500">Provider ativo</dt><dd class="font-semibold">{{ $health['openai']['active_provider'] }}</dd></div>
            <div><dt class="text-stone-500">API Key</dt><dd class="font-semibold">{{ $health['openai']['key_configured'] ? 'Configurada' : 'Ausente' }}</dd></div>
            <div class="col-span-2"><dt class="text-stone-500">Modelo</dt><dd class="break-all font-semibold">{{ $health['openai']['model'] }}</dd></div>
            <div><dt class="text-stone-500">Responses API</dt><dd class="font-semibold">Pronta</dd></div>
            <div><dt class="text-stone-500">Visão / PDF</dt><dd class="font-semibold">{{ $health['openai']['vision'] && $health['openai']['document'] ? 'Configurado' : 'Pendente' }}</dd></div>
            <div><dt class="text-stone-500">Transcrição</dt><dd class="font-semibold">{{ $health['openai']['transcription'] ? 'Configurada' : 'Pendente' }}</dd></div>
            <div><dt class="text-stone-500">Uso no mês</dt><dd class="font-semibold">{{ $health['openai']['usage_count'] }} chamada(s)</dd></div>
            <div class="col-span-2"><dt class="text-stone-500">Custo rastreado</dt><dd class="font-semibold">R$ {{ $health['openai']['cost_brl'] }}</dd></div>
        </dl>
    </article>

    <article class="form-card border {{ $statusClass($health['whatsapp']['status']) }}">
        <div class="flex items-center justify-between gap-3"><h2 class="section-title">WhatsApp</h2><span class="status-badge">{{ $statusIcon($health['whatsapp']['status']) }} {{ $health['whatsapp']['status'] }}</span></div>
        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-stone-500">Provider</dt><dd class="font-semibold">{{ $health['whatsapp']['provider'] }}</dd></div>
            <div><dt class="text-stone-500">Canal</dt><dd class="font-semibold">{{ $health['whatsapp']['enabled'] ? 'Habilitado' : 'Desabilitado' }}</dd></div>
            <div class="col-span-2"><dt class="text-stone-500">Número empresarial</dt><dd class="font-semibold">{{ $health['whatsapp']['business_phone'] }}</dd></div>
            <div><dt class="text-stone-500">Webhook HTTPS</dt><dd class="font-semibold">{{ $health['whatsapp']['public_webhook'] ? 'Pronto' : 'Pendente' }}</dd></div>
            <div><dt class="text-stone-500">Token</dt><dd class="font-semibold">{{ $health['whatsapp']['meta_assets']['token'] ? 'Configurado' : 'Ausente' }}</dd></div>
            <div><dt class="text-stone-500">WABA</dt><dd class="font-semibold">{{ $health['whatsapp']['meta_assets']['waba'] ? 'Configurado' : 'Ausente' }}</dd></div>
            <div><dt class="text-stone-500">Phone Number ID</dt><dd class="font-semibold">{{ $health['whatsapp']['meta_assets']['phone'] ? 'Configurado' : 'Ausente' }}</dd></div>
        </dl>
    </article>
</section>

<section class="form-card mb-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
            <h2 class="section-title">Conectar WhatsApp oficial</h2>
            <p class="mt-2 text-sm text-stone-600">A conexão permanente usa Meta App, WABA, Phone Number ID, token, App Secret e webhook HTTPS. O número empresarial nunca representa um usuário do ERP.</p>
            <p class="mt-3 text-sm"><strong>Coexistence:</strong> {{ $health['coexistence']['status'] }} · <strong>Embedded Signup:</strong> {{ $health['coexistence']['embedded_signup_status'] }} · <strong>QR oficial:</strong> {{ $health['coexistence']['official_qr'] }}</p>
        </div>
        <button class="btn-primary cursor-not-allowed opacity-60" type="button" disabled title="Exige configuração oficial na Meta e webhook HTTPS">Conectar WhatsApp</button>
    </div>
    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach([
            'Meta App' => $health['whatsapp']['meta_assets']['app'],
            'Embedded Signup' => $health['whatsapp']['meta_assets']['embedded_signup'],
            'App Secret' => $health['whatsapp']['meta_assets']['app_secret'],
            'Verify Token' => $health['whatsapp']['meta_assets']['verify_token'],
        ] as $label => $configured)
            <div class="rounded-xl border border-stone-200 p-3"><span class="form-label">{{ $label }}</span><strong>{{ $configured ? 'Configurado' : 'Pendente' }}</strong></div>
        @endforeach
    </div>
</section>

<section class="mb-6 grid gap-6 lg:grid-cols-2">
    <form class="form-card" method="POST" action="{{ route('agent.whatsapp.business-phone.update') }}">
        @csrf @method('PUT')
        <h2 class="section-title">Número empresarial do ERP</h2>
        <p class="mt-2 text-sm text-stone-600">Cadastre somente o destino empresarial conectado à Meta. Telefones de usuários são autorizados na área de identidades.</p>
        <label class="mt-4 block"><span class="form-label">Telefone empresarial com DDD</span><input class="form-input" name="business_phone" value="{{ old('business_phone') }}" placeholder="(17) 99999-9999" autocomplete="off"></label>
        <label class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm"><input class="mt-1" type="checkbox" name="confirm_business_phone" value="1"><span><strong>Confirmo que este é o número empresarial.</strong><br>Ele não será vinculado a usuário, cargo, permissão ou unidade.</span></label>
        <button class="btn-secondary mt-4">Salvar número empresarial</button>
    </form>

    <div class="form-card">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="section-title">Health check Meta</h2><p class="mt-2 text-sm text-stone-600">Consulta somente leitura do Phone Number ID configurado.</p></div><form method="POST" action="{{ route('agent.whatsapp.check') }}">@csrf<button class="btn-secondary" @disabled(! $health['whatsapp']['can_check_meta'])>Verificar disponibilidade</button></form></div>
        <dl class="mt-5 grid gap-3 sm:grid-cols-2 text-sm">
            <div><dt class="text-stone-500">Última verificação</dt><dd>{{ $health['whatsapp']['last_check']?->format('d/m/Y H:i:s') ?? 'Nunca' }}</dd></div>
            <div><dt class="text-stone-500">Tipo de token declarado</dt><dd>{{ $health['whatsapp']['token_type'] }}</dd></div>
            <div><dt class="text-stone-500">Último inbound</dt><dd>{{ $health['whatsapp']['last_inbound']?->format('d/m/Y H:i:s') ?? 'Nunca' }}</dd></div>
            <div><dt class="text-stone-500">Último outbound</dt><dd>{{ $health['whatsapp']['last_outbound']?->format('d/m/Y H:i:s') ?? 'Nunca' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-stone-500">Último erro sanitizado</dt><dd>{{ $health['whatsapp']['last_error'] ?? 'Nenhum' }}</dd></div>
        </dl>
    </div>
</section>

<section>
    <div class="mb-3 flex items-center justify-between"><h2 class="section-title">Mensagens pendentes / com erro</h2><span class="status-badge {{ $pendingCount ? 'status-inactive' : 'status-active' }}">{{ $pendingCount }}</span></div>
    <div class="space-y-3 md:hidden">
        @forelse($messages as $message)
            <article class="form-card text-sm"><div class="flex items-start justify-between gap-3"><strong>{{ $phones->mask($message->external_user_id) }}</strong><span class="status-badge">{{ $message->status }}</span></div><p class="mt-2 text-stone-600">{{ $message->message_type }} · {{ $message->attempts }} tentativa(s)</p><p class="mt-1">{{ $message->original_timestamp?->format('d/m/Y H:i:s') ?? $message->received_at->format('d/m/Y H:i:s') }}</p>@if($message->error_code)<p class="mt-1 text-red-700">{{ $message->error_code }}</p>@endif</article>
        @empty
            <div class="form-card empty-state">Nenhuma mensagem recebida.</div>
        @endforelse
    </div>
    <div class="table-card hidden md:block"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Horário original</th><th>Remetente</th><th>Tipo</th><th>Status</th><th>Tentativas</th><th>Erro</th></tr></thead><tbody>@forelse($messages as $message)<tr><td>{{ $message->original_timestamp?->format('d/m/Y H:i:s') ?? $message->received_at->format('d/m/Y H:i:s') }}</td><td>{{ $phones->mask($message->external_user_id) }}</td><td>{{ $message->message_type }}</td><td>{{ $message->status }}</td><td>{{ $message->attempts }}</td><td>{{ $message->error_code ?? '—' }}</td></tr>@empty<tr><td colspan="6" class="empty-state">Nenhuma mensagem recebida.</td></tr>@endforelse</tbody></table></div></div>
    <div class="mt-4">{{ $messages->links() }}</div>
</section>
@endsection
