@extends('layouts.app')
@section('title', 'Editar fornecedor')
@section('content')
    <div class="mb-6"><h1 class="page-title">Editar fornecedor</h1><p class="page-subtitle">{{ $supplier->name }}</p></div>
    <form method="POST" action="{{ route('suppliers.update', $supplier) }}" class="form-card">@csrf @method('PUT') @include('suppliers._form')</form>
@endsection
