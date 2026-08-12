@extends('layouts.app')
@section('title', 'Editar categoria de insumo')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Editar categoria de insumo</h1><p class="page-subtitle">{{ $ingredientCategory->name }}</p></div></div>
    <form class="form-card max-w-3xl" method="POST" action="{{ route('ingredient-categories.update', $ingredientCategory) }}">@csrf @method('PUT') @include('ingredient-categories._form')</form>
@endsection
