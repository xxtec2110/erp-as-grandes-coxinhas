@extends('layouts.app')
@section('title','Ordens de produção')
@section('content')
<div class="page-header"><div><h1 class="page-title">Ordens de produção</h1><p class="page-subtitle">Produção multi-item com ficha, consumo e custo congelados.</p></div><a class="btn-primary" href="{{ route('production-orders.create') }}">Nova ordem</a></div>
<div class="table-card"><table class="data-table"><thead><tr><th>Data</th><th>Unidade</th><th>Itens</th><th>Status</th><th></th></tr></thead><tbody>@forelse($orders as $order)<tr><td>{{ $order->production_date->format('d/m/Y') }}</td><td>{{ $order->location->name }}</td><td>{{ $order->items->count() }}</td><td>{{ ucfirst($order->status) }}</td><td><a class="text-link" href="{{ route('production-orders.show',$order) }}">Abrir</a></td></tr>@empty<tr><td colspan="5" class="empty-state">Nenhuma ordem.</td></tr>@endforelse</tbody></table></div>{{ $orders->links() }}
@endsection
