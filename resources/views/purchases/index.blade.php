@extends('layouts.app')
@section('title', 'Documentos de compra')
@section('content')
<div class="page-header">
    <div><h1 class="page-title">Compras e documentos de fornecedor</h1><p class="page-subtitle">Histórico econômico, recebimento físico separado e rastreabilidade por unidade.</p></div>
    <div class="flex flex-wrap gap-2"><a class="btn-secondary" href="{{ route('costs.index') }}">Custos e margens</a><a class="btn-secondary" href="{{ route('purchase-imports.index') }}">Revisões por foto</a><a class="btn-primary" href="{{ route('purchases.create') }}">Novo documento</a></div>
</div>
<div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Emissão</th><th>Fornecedor</th><th>Documento</th><th>Origem</th><th>Unidade</th><th>Recebimento</th><th>Total</th><th></th></tr></thead><tbody>
@forelse($documents as $document)<tr><td>{{ $document->issue_date->format('d/m/Y') }}</td><td>{{ $document->supplier?->name ?? 'Não identificado' }}</td><td>{{ $document->document_type }} {{ $document->document_number }}</td><td>{{ str_replace('_',' ',$document->source_type ?? 'purchase') }}</td><td>{{ $document->location->name }}</td><td>{{ str_replace('_',' ',$document->receipt_status) }}</td><td>R$ {{ \App\Support\DecimalFormatter::format($document->total_amount,2) }}</td><td><a class="font-semibold text-amber-700 hover:text-amber-900" href="{{ route('purchases.show', $document) }}">Abrir</a></td></tr>
@empty<tr><td colspan="8" class="empty-state">Nenhum documento.</td></tr>@endforelse
</tbody></table></div></div>{{ $documents->links() }}
@endsection
