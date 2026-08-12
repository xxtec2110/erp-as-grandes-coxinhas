@extends('layouts.app')
@section('title', 'Novo fornecedor')
@section('content')
    <div class="mb-6"><h1 class="page-title">Novo fornecedor</h1><p class="page-subtitle">Informe os dados básicos do fornecedor.</p></div>
    <form method="POST" action="{{ route('suppliers.store') }}" class="form-card">@csrf @include('suppliers._form', ['supplier' => null])</form>
@endsection
