@extends('layouts.app')
@section('title', 'Editar unidade')
@section('content')
    <div class="mb-6"><h1 class="page-title">Editar unidade</h1><p class="page-subtitle">{{ $location->name }}</p></div>
    <form method="POST" action="{{ route('locations.update', $location) }}" class="form-card">@csrf @method('PUT') @include('locations._form')</form>
@endsection
