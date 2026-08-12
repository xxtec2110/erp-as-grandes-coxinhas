@extends('layouts.app')
@section('title', 'Novo GLP')
@section('content')
    <div class="mb-6"><h1 class="page-title">Novo recipiente de GLP</h1><p class="page-subtitle">Cadastre P13, P20, P45 ou outra forma de fornecimento.</p></div>
    <form method="POST" action="{{ route('glp-products.store') }}" class="form-card">@csrf @include('glp._form', ['glpProduct' => null])</form>
@endsection
