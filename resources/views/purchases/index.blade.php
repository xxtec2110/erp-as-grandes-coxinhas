@extends('layouts.app')
@section('title','Documentos de compra')
@section('content')
<div class="page-header"><div><h1 class="page-title">Documentos de fornecedor</h1><p class="page-subtitle">Notas, boletos, faturas e recibos com rastreabilidade.</p></div><a class="btn-primary" href="{{ route('purchases.create') }}">Novo documento</a></div><div class="table-card"><table class="data-table"><thead><tr><th>Emissão</th><th>Fornecedor</th><th>Documento</th><th>Unidade</th><th>Total</th></tr></thead><tbody>@forelse($documents as $d)<tr><td>{{ $d->issue_date->format('d/m/Y') }}</td><td>{{ $d->supplier?->name??'Não identificado' }}</td><td>{{ $d->document_type }} {{ $d->document_number }}</td><td>{{ $d->location->name }}</td><td>R$ {{ \App\Support\DecimalFormatter::format($d->total_amount,2) }}</td></tr>@empty<tr><td colspan="5" class="empty-state">Nenhum documento.</td></tr>@endforelse</tbody></table></div>{{ $documents->links() }}
@endsection
