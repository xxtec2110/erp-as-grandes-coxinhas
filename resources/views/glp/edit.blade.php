@extends('layouts.app')
@section('title', 'Editar GLP')
@section('content')
    <div class="mb-6"><h1 class="page-title">Editar recipiente de GLP</h1><p class="page-subtitle">{{ $glpProduct->name }}</p></div>
    <form method="POST" action="{{ route('glp-products.update', $glpProduct) }}" class="form-card">@csrf @method('PUT') @include('glp._form')</form>
@endsection
