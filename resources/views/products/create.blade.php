@extends('layouts.app')
@section('title', 'Novo produto')
@section('content')
    <div class="mb-6"><h1 class="page-title">Novo produto</h1><p class="page-subtitle">Cadastre um produto final estocável.</p></div>
    <form method="POST" action="{{ route('products.store') }}" class="form-card">@csrf @include('products._form', ['product' => null, 'onboarding' => $onboarding ?? null])</form>
@endsection
