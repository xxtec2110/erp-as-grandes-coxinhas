@extends('layouts.app')
@section('title', 'Editar equipamento')
@section('content')
    <div class="mb-6"><h1 class="page-title">Editar equipamento</h1><p class="page-subtitle">{{ $equipment->name }}</p></div>
    <form method="POST" action="{{ route('equipment.update', $equipment) }}" class="form-card">@csrf @method('PUT') @include('equipment._form')</form>
@endsection
