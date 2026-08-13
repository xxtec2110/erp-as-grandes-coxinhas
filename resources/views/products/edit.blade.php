@extends('layouts.app')
@section('title', 'Editar produto')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Editar produto</h1><p class="page-subtitle">{{ $product->name }}</p></div><a class="btn-secondary" href="{{ route('products.recipe.edit',$product) }}">Ficha técnica / montagem</a></div>
    <form method="POST" action="{{ route('products.update', $product) }}" class="form-card">@csrf @method('PUT') @include('products._form')</form>
@endsection
