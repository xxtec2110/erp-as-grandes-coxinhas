@extends('layouts.app')
@section('title', 'Transferências')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Transferências e recebimentos</h1><p class="page-subtitle">Movimentação rastreável de produtos entre unidades.</p></div><a class="btn-primary" href="{{ route('transfers.create') }}">Nova transferência</a></div>
    <div class="table-card"><div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Data</th><th>Origem</th><th>Destino</th><th>Itens</th><th>Status</th><th class="text-right">Ação</th></tr></thead><tbody>
        @forelse ($transfers as $transfer)<tr><td>{{ $transfer->operation_date->format('d/m/Y') }}</td><td>{{ $transfer->sourceLocation->name }}</td><td>{{ $transfer->destinationLocation->name }}</td><td>@foreach ($transfer->items as $item)<span class="block">{{ $item->product->name }}: {{ \App\Support\DecimalFormatter::format($item->quantity_sent, $item->product->stock_unit === 'un' ? 0 : 3) }} {{ $item->product->stock_unit }}</span>@endforeach</td><td><span class="status-badge {{ $transfer->status === \App\Enums\StockTransferStatus::Received ? 'status-active' : 'status-inactive' }}">{{ $transfer->status->label() }}</span></td><td class="text-right"><a class="text-link" href="{{ route('transfers.show', $transfer) }}">Abrir</a></td></tr>
        @empty <tr><td colspan="6" class="empty-state">Nenhuma transferência registrada.</td></tr> @endforelse
    </tbody></table></div></div><div class="mt-5">{{ $transfers->links() }}</div>
@endsection
