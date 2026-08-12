@extends('layouts.app')
@section('title', 'Políticas de estoque')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Políticas de estoque</h1><p class="page-subtitle">Estoque mínimo e alvo por produto e unidade.</p></div><a class="btn-primary" href="{{ route('stock-policies.create') }}">Nova política</a></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Unidade</th><th>Produto</th><th>Mínimo</th><th>Alvo</th><th>Prioridade</th><th>Status</th><th class="text-right">Ação</th></tr></thead><tbody>
        @forelse ($policies as $policy)<tr><td>{{ $policy->location->name }}</td><td class="font-semibold">{{ $policy->product->name }}</td><td>{{ $policy->minimum_quantity !== null ? \App\Support\DecimalFormatter::format($policy->minimum_quantity, $policy->product->stock_unit === 'un' ? 0 : 3) : '—' }}</td><td>{{ \App\Support\DecimalFormatter::format($policy->target_quantity, $policy->product->stock_unit === 'un' ? 0 : 3) }}</td><td>{{ $policy->production_priority }}</td><td><span class="status-badge {{ $policy->active ? 'status-active' : 'status-inactive' }}">{{ $policy->active ? 'Ativa' : 'Inativa' }}</span></td><td class="text-right"><a class="text-link" href="{{ route('stock-policies.edit', $policy) }}">Editar</a></td></tr>
        @empty <tr><td colspan="7" class="empty-state">Nenhuma política cadastrada.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $policies->links() }}</div>
@endsection
