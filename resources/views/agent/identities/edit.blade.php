@extends('layouts.app')
@section('title', 'Gerenciar acesso WhatsApp')
@section('content')
<div class="page-header">
    <div><h1 class="page-title">{{ $identity->display_name ?: ($identity->user?->name ?? 'Usuário removido') }}</h1><p class="page-subtitle">{{ $identity->phone_normalized }} · WhatsApp · {{ $identity->active ? 'Acesso ativo' : 'Acesso inativo' }}</p></div>
    <a class="btn-secondary" href="{{ route('agent.identities.index') }}">Voltar</a>
</div>
@if(session('success'))<div class="mb-5 rounded-xl bg-green-50 p-4 text-green-800">{{ session('success') }}</div>@endif
@if($errors->any())<div class="mb-5 rounded-xl bg-red-50 p-4 text-red-800">{{ $errors->first() }}</div>@endif

<div class="mb-6 grid gap-4 lg:grid-cols-2">
    <section class="form-card">
        <h2 class="section-title">Identidade oficial</h2>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-stone-500">Usuário ERP</dt><dd class="font-semibold">{{ $identity->user?->name ?? '—' }}</dd></div>
            <div><dt class="text-stone-500">Telefone normalizado</dt><dd>{{ $identity->phone_normalized }}</dd></div>
            <div><dt class="text-stone-500">Perfil</dt><dd>{{ $identity->user?->roles?->pluck('label')->join(', ') ?: '—' }}</dd></div>
            <div><dt class="text-stone-500">Responder</dt><dd>{{ $identity->respond_enabled ? 'Habilitado' : 'Desabilitado' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-stone-500">Unidades autorizadas</dt><dd>{{ $identity->user?->all_locations_access ? 'Todas' : ($identity->user?->locations?->pluck('name')->join(', ') ?: 'Nenhuma') }}</dd></div>
            <div><dt class="text-stone-500">Criado em</dt><dd>{{ $identity->created_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-stone-500">Ativado por</dt><dd>{{ $identity->approver?->name ?? '—' }} · {{ $identity->activated_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-stone-500">Último contato</dt><dd>{{ $identity->last_authorized_inbound_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-stone-500">Última alteração</dt><dd>{{ $identity->updated_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
        </dl>
    </section>
    <section class="form-card">
        <h2 class="section-title">O que o agente pode fazer</h2>
        <p class="mt-1 text-sm text-stone-600">Resumo amigável, calculado agora a partir das Tools e permissões do usuário.</p>
        <div class="mt-4 flex flex-wrap gap-2">@forelse($capabilities as $capability)<span class="status-badge status-active">{{ $capability }}</span>@empty<span class="text-sm text-stone-500">Nenhuma área operacional liberada.</span>@endforelse</div>
        <p class="mt-4 text-xs text-stone-500">Cargos, permissões e unidades são administrados no cadastro do usuário, nunca no telefone.</p>
    </section>
</div>

@if($canManage)
<form method="POST" action="{{ route('agent.identities.update', $identity) }}" class="space-y-6">@csrf @method('PUT')
    <section class="form-card">
        <h2 class="section-title">Vínculo e política do canal</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <label><span class="form-label">Nome amigável</span><input class="form-input" name="display_name" value="{{ old('display_name', $identity->display_name) }}" maxlength="120"></label>
            <label><span class="form-label">Usuário ERP</span><select class="form-input" name="user_id" required>@foreach($users as $user)<option value="{{ $user->id }}" @selected((int) old('user_id', $identity->user_id) === $user->id)>{{ $user->name }} · {{ $user->roles->pluck('label')->join(', ') ?: 'Sem perfil' }}</option>@endforeach</select></label>
            <label><span class="form-label">Status</span><select class="form-input" name="status">@foreach(['approved'=>'Aprovado','blocked'=>'Bloqueado','inactive'=>'Inativo'] as $key=>$label)<option value="{{ $key }}" @selected(old('status', $identity->status) === $key)>{{ $label }}</option>@endforeach</select></label>
            <div class="space-y-2 pt-1 sm:pt-6">
                <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $identity->active))> Acesso ativo</label>
                <input type="hidden" name="respond_enabled" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="respond_enabled" value="1" @checked(old('respond_enabled', $identity->respond_enabled))> Agente pode responder</label>
            </div>
        </div>
        <label class="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><input class="mt-1" type="checkbox" name="confirm_user_change" value="1"><span>Confirmo a troca de usuário caso tenha alterado o vínculo. O registro anterior será preservado e ações pendentes daquele vínculo serão canceladas.</span></label>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">@foreach(['menu_enabled'=>'Menu','structured_commands_allowed'=>'Comandos','free_chat_allowed'=>'Conversa livre','voice_allowed'=>'Áudio','image_allowed'=>'Imagem','document_allowed'=>'Documentos','reports_allowed'=>'Relatórios'] as $field=>$label)<label class="flex gap-2"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $identity->$field))> {{ $label }}</label>@endforeach</div>
        <button class="btn-primary mt-5">Salvar identidade</button>
    </section>
</form>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <form method="POST" action="{{ route('agent.identities.phone', $identity) }}" class="form-card">@csrf @method('PUT')
        <h2 class="section-title">Trocar telefone</h2><p class="mt-1 text-sm text-stone-600">O número atual será desativado, seu histórico permanecerá intacto e as ações pendentes do vínculo serão canceladas.</p>
        <label class="mt-4 block"><span class="form-label">Novo telefone</span><input class="form-input" name="phone" placeholder="(17) 99999-9999" required></label>
        <label class="mt-3 flex gap-2"><input type="checkbox" name="confirm_replace" value="1" required> Confirmo a substituição explícita do telefone</label>
        <button class="btn-secondary mt-4">Trocar com segurança</button>
    </form>
    <form method="POST" action="{{ route('agent.identities.welcome', $identity) }}" class="form-card">@csrf
        <h2 class="section-title">Boas-vindas</h2><p class="mt-1 text-sm text-stone-600">Status: {{ $identity->welcome_status }}. Com Meta desligada, nenhuma mensagem real será enviada.</p>
        <button class="btn-secondary mt-4" @disabled(!$identity->active || !$identity->respond_enabled)>Solicitar boas-vindas</button>
    </form>
</div>
@endif
@endsection
