@extends('layouts.app')
@section('title', 'Unidades')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Unidades operacionais</h1><p class="page-subtitle">Locais de produção e lojas.</p></div><a class="btn-primary" href="{{ route('locations.create') }}">Nova unidade</a></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Nome</th><th>Tipo</th><th>Status</th><th class="text-right">Ação</th></tr></thead><tbody>
        @forelse ($locations as $location)<tr><td class="font-semibold">{{ $location->name }}</td><td>{{ $location->typeLabel() }}</td><td><span class="status-badge {{ $location->active ? 'status-active' : 'status-inactive' }}">{{ $location->active ? 'Ativa' : 'Inativa' }}</span></td><td class="text-right"><a class="text-link" href="{{ route('locations.edit', $location) }}">Editar</a></td></tr>
        @empty <tr><td colspan="4" class="empty-state">Nenhuma unidade cadastrada.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $locations->links() }}</div>
@endsection
