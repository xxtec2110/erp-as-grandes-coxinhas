@extends('layouts.app')
@section('title', 'Usuários e acessos')
@section('content')
<div class="page-header"><div><h1 class="page-title">Usuários e acessos</h1><p class="page-subtitle">Perfis, exceções individuais e unidades autorizadas.</p></div></div>
<div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Usuário</th><th>E-mail</th><th>Perfis</th><th>Escopo</th><th>Ação</th></tr></thead><tbody>
@foreach($users as $user)<tr><td class="font-semibold">{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->roles->pluck('label')->join(', ') ?: 'Sem perfil' }}</td><td>{{ $user->is_super_admin || $user->all_locations_access ? 'Todas as unidades' : 'Unidades selecionadas' }}</td><td><a class="text-link" href="{{ route('users.access.edit', $user) }}">Gerenciar</a></td></tr>@endforeach
</tbody></table></div></div><div class="mt-5">{{ $users->links() }}</div>
@endsection
