@extends('layouts.app')
@section('title', 'Registrar perda')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Registrar perda</h1><p class="page-subtitle">A perda será baixada do estoque da unidade informada.</p></div><a class="btn-secondary" href="{{ route('losses.index') }}">Voltar</a></div>
    <form method="POST" action="{{ route('losses.store') }}" class="form-card">@csrf
        @if ($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}"><div class="grid gap-5 sm:grid-cols-2">
            <div><label class="form-label" for="location_id">Unidade</label><select id="location_id" name="location_id" class="form-input" required><option value="">Selecione</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></div>
            <div><label class="form-label" for="product_id">Produto</label><select id="product_id" name="product_id" class="form-input" required><option value="">Selecione</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} ({{ $product->stock_unit }})</option>@endforeach</select></div>
            <div><label class="form-label" for="loss_reason_id">Motivo</label><select id="loss_reason_id" name="loss_reason_id" class="form-input" required><option value="">Selecione</option>@foreach($reasons as $reason)<option value="{{ $reason->id }}">{{ $reason->name }}</option>@endforeach</select></div>
            <div><label class="form-label" for="quantity">Quantidade</label><input id="quantity" name="quantity" type="number" min="0.000001" step="0.000001" class="form-input" required></div>
            <div><label class="form-label" for="operation_date">Data real</label><input id="operation_date" name="operation_date" type="date" class="form-input" value="{{ old('operation_date', now()->toDateString()) }}" required></div>
            <div class="sm:col-span-2"><label class="form-label" for="notes">Observações</label><textarea id="notes" name="notes" rows="4" class="form-input">{{ old('notes') }}</textarea></div>
        </div><button class="btn-primary mt-6" type="submit">Registrar perda</button>
    </form>
@endsection
