@extends('layouts.app')
@section('title', 'Enviar nota por foto')
@section('content')
<div class="page-header"><div><h1 class="page-title">Enviar nota, cupom ou pedido</h1><p class="page-subtitle">Você pode enviar uma ou várias páginas. A interpretação será apenas uma proposta para revisão.</p></div><a class="btn-secondary" href="{{ route('purchase-imports.index') }}">Voltar</a></div>
@if($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ $errors->first() }}</div>@endif
<form class="form-card max-w-3xl" method="POST" action="{{ route('purchase-imports.store') }}" enctype="multipart/form-data">@csrf
    <label><span class="form-label">Unidade que realizou a compra</span><select class="form-input" name="location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(old('location_id') == $location->id)>{{ $location->name }}</option>@endforeach</select></label>
    <label class="mt-5 block"><span class="form-label">Fotos ou PDF</span><input class="form-input" type="file" name="attachments[]" accept="image/jpeg,image/png,application/pdf" multiple required><span class="mt-2 block text-sm text-stone-500">Até 10 arquivos. Evite repetir a mesma página e inclua a página com o total.</span></label>
    <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"><strong>Importante:</strong> fornecedor, itens, preços, recebimento e totais só serão gravados depois da sua revisão e confirmação.</div>
    <button class="btn-primary mt-5">Enviar para revisão</button>
</form>
@endsection
