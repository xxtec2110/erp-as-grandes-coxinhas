@extends('layouts.app')
@section('title', 'Editar insumo')
@section('content')
    <div class="mb-6"><h1 class="page-title">Editar insumo</h1><p class="page-subtitle">{{ $ingredient->name }}</p></div>
    <form method="POST" action="{{ route('ingredients.update', $ingredient) }}" class="form-card">@csrf @method('PUT') @include('ingredients._form')</form>
@endsection
