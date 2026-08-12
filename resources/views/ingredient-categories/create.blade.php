@extends('layouts.app')
@section('title', 'Nova categoria de insumo')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Nova categoria de insumo</h1><p class="page-subtitle">Crie uma classificação para organizar o cadastro de insumos.</p></div></div>
    <form class="form-card max-w-3xl" method="POST" action="{{ route('ingredient-categories.store') }}">@csrf @include('ingredient-categories._form', ['ingredientCategory' => null])</form>
@endsection
