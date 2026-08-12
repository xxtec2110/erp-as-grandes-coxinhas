@extends('layouts.app')
@section('title', 'Editar produto')
@section('content')
    <div class="mb-6"><h1 class="page-title">Editar produto</h1><p class="page-subtitle">{{ $product->name }}</p></div>
    <form method="POST" action="{{ route('products.update', $product) }}" class="form-card">@csrf @method('PUT') @include('products._form')</form>
@endsection
