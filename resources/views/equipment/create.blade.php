@extends('layouts.app')
@section('title', 'Novo equipamento')
@section('content')
    <div class="mb-6"><h1 class="page-title">Novo equipamento</h1><p class="page-subtitle">Todos os valores técnicos poderão ser ajustados posteriormente.</p></div>
    <form method="POST" action="{{ route('equipment.store') }}" class="form-card">@csrf @include('equipment._form', ['equipment' => null])</form>
@endsection
