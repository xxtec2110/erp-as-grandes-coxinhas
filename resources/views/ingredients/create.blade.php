@extends('layouts.app')
@section('title', 'Novo insumo')
@section('content')
    <div class="mb-6"><h1 class="page-title">Novo insumo</h1><p class="page-subtitle">Defina a unidade usada nos cálculos das fichas técnicas.</p></div>
    <form method="POST" action="{{ route('ingredients.store') }}" class="form-card">@csrf @include('ingredients._form', ['ingredient' => null])</form>
@endsection
