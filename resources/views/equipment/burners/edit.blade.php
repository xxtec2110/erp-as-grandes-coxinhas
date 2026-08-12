@extends('layouts.app')
@section('title', 'Editar queimador')
@section('content')
    <div class="mb-6"><h1 class="page-title">Editar queimador</h1><p class="page-subtitle">{{ $equipment->name }} — {{ $burner->name }}</p></div>
    <form method="POST" action="{{ route('equipment.burners.update', [$equipment, $burner]) }}" class="form-card">@csrf @method('PUT') @include('equipment.burners._form')<div class="mt-6 flex gap-3"><button class="btn-primary" type="submit">Salvar queimador</button><a class="btn-secondary" href="{{ route('equipment.show', $equipment) }}">Cancelar</a></div></form>
@endsection
