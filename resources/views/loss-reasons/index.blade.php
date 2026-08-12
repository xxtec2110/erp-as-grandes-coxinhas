@extends('layouts.app')
@section('title', 'Motivos de perda')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Motivos de perda</h1><p class="page-subtitle">Classificações reutilizadas nos lançamentos operacionais.</p></div><a class="btn-secondary" href="{{ route('losses.index') }}">Ir para perdas</a></div>
    <form method="POST" action="{{ route('loss-reasons.store') }}" class="form-card mb-6">@csrf<div class="grid gap-4 sm:grid-cols-[1fr_auto_auto] sm:items-end"><div><label class="form-label" for="name">Novo motivo</label><input id="name" name="name" class="form-input" required></div><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="active" value="1" checked> Ativo</label><button class="btn-primary" type="submit">Adicionar</button></div></form>
    <div class="space-y-3">@forelse($reasons as $reason)<form method="POST" action="{{ route('loss-reasons.update', $reason) }}" class="form-card flex flex-col gap-3 sm:flex-row sm:items-center">@csrf @method('PUT')<input name="name" class="form-input" value="{{ $reason->name }}" required><label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked($reason->active)> Ativo</label><button class="btn-secondary" type="submit">Salvar</button></form>@empty<p class="empty-state">Nenhum motivo cadastrado.</p>@endforelse</div>
@endsection
