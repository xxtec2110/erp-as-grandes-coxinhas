@extends('layouts.app')
@section('title', 'Notas por foto')
@section('content')
<div class="page-header">
    <div><h1 class="page-title">Notas e comprovantes por foto</h1><p class="page-subtitle">Documentos privados em revisão. Nenhum rascunho altera compras, preços ou estoque.</p></div>
    <div class="flex flex-wrap gap-2"><a class="btn-secondary" href="{{ route('purchases.index') }}">Compras</a><a class="btn-primary" href="{{ route('purchase-imports.create') }}">Enviar foto</a></div>
</div>
<div class="table-card"><div class="overflow-x-auto"><table class="data-table">
    <thead><tr><th>Envio</th><th>Status</th><th>Fornecedor</th><th>Documento</th><th>Unidade</th><th>Arquivos</th><th></th></tr></thead>
    <tbody>@forelse($imports as $import)<tr>
        <td>{{ $import->created_at->format('d/m/Y H:i') }}</td>
        <td><span class="status-badge {{ $import->status === 'confirmed' ? 'status-active' : ($import->status === 'cancelled' ? 'status-inactive' : '') }}">{{ str_replace('_', ' ', $import->status) }}</span></td>
        <td>{{ $import->supplier?->name ?? $import->supplier_name_extracted ?? 'Pendente' }}</td>
        <td>{{ $import->document_number ?: 'Sem número' }}</td><td>{{ $import->location->name }}</td><td>{{ $import->attachments->count() }}</td>
        <td><a class="font-semibold text-amber-700" href="{{ route('purchase-imports.show', $import) }}">Revisar</a></td>
    </tr>@empty<tr><td colspan="7" class="empty-state">Nenhum documento em revisão.</td></tr>@endforelse</tbody>
</table></div></div>
{{ $imports->links() }}
@endsection
