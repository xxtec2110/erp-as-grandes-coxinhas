@extends('layouts.app')
@section('title', 'Nova unidade')
@section('content')
    <div class="mb-6"><h1 class="page-title">Nova unidade</h1><p class="page-subtitle">Cadastre uma produção ou loja.</p></div>
    <form method="POST" action="{{ route('locations.store') }}" class="form-card">@csrf @include('locations._form', ['location' => null])</form>
@endsection
