@extends('layouts.app')
@section('title', 'Preparos')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Preparações / Receitas Base</h1><p class="page-subtitle">Ingredientes, rendimento real e custo atual de cada preparo.</p></div><a class="btn-primary" href="{{ route('preparations.create') }}">Nova preparação</a></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Preparação</th><th>Rendimento esperado</th><th>Tempo</th><th>Ingredientes</th><th>Status</th><th class="text-right">Ações</th></tr></thead><tbody>
        @forelse ($preparations as $preparation)<tr><td class="font-semibold">{{ $preparation->name }}</td><td>{{ \App\Support\DecimalFormatter::format($preparation->expected_yield, 3) }} {{ $preparation->yield_unit }}</td><td>{{ $preparation->total_preparation_time_minutes }} min</td><td>{{ $preparation->preparation_ingredients_count }}</td><td><span class="status-badge {{ $preparation->active ? 'status-active' : 'status-inactive' }}">{{ $preparation->active ? 'Ativa' : 'Inativa' }}</span></td><td class="whitespace-nowrap text-right"><a class="text-link" href="{{ route('preparations.show', $preparation) }}">Abrir</a><a class="ml-3 text-link" href="{{ route('preparations.edit', $preparation) }}">Editar</a></td></tr>
        @empty <tr><td class="empty-state" colspan="6">Nenhuma preparação cadastrada.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $preparations->links() }}</div>
@endsection
