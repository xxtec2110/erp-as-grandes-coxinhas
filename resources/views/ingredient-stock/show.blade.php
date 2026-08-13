@extends('layouts.app')
@section('title','Histórico de insumo')
@section('content')
<div class="page-header"><div><h1 class="page-title">{{ $ingredient->name }}</h1><p class="page-subtitle">Histórico em {{ $location->name }}</p></div><a class="btn-secondary" href="{{ route('ingredient-stock.index',['location_id'=>$location->id]) }}">Voltar</a></div><div class="table-card"><table class="data-table"><thead><tr><th>Data</th><th>Tipo</th><th>Quantidade</th><th>Origem</th><th>Observação</th></tr></thead><tbody>@foreach($movements as $movement)<tr><td>{{ $movement->operation_date->format('d/m/Y') }}</td><td>{{ $movement->type }}</td><td>{{ $movement->quantity_delta }} {{ $ingredient->base_unit }}</td><td>{{ $movement->source }}</td><td>{{ $movement->notes }}</td></tr>@endforeach</tbody></table></div>{{ $movements->links() }}
@endsection
