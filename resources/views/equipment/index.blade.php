@extends('layouts.app')
@section('title', 'Equipamentos')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Equipamentos de produção</h1><p class="page-subtitle">Cadastre equipamentos, consumo nominal e queimadores.</p></div><a class="btn-primary" href="{{ route('equipment.create') }}">Novo equipamento</a></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Equipamento</th><th>Tipo</th><th>Energia</th><th>Queimadores</th><th>Fator</th><th>Status</th><th class="text-right">Ações</th></tr></thead><tbody>
        @forelse ($equipment as $item)<tr><td class="font-semibold">{{ $item->name }}</td><td>{{ $item->type }}</td><td>{{ $item->energySourceLabel() }}</td><td>{{ $item->burners_count }}</td><td>{{ \App\Support\DecimalFormatter::format($item->default_utilization_factor, 3) }}</td><td><span class="status-badge {{ $item->active ? 'status-active' : 'status-inactive' }}">{{ $item->active ? 'Ativo' : 'Inativo' }}</span></td><td class="whitespace-nowrap text-right"><a class="text-link" href="{{ route('equipment.show', $item) }}">Abrir</a><a class="ml-3 text-link" href="{{ route('equipment.edit', $item) }}">Editar</a></td></tr>
        @empty <tr><td colspan="7" class="empty-state">Nenhum equipamento cadastrado.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $equipment->links() }}</div>
@endsection
