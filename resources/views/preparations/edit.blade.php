@extends('layouts.app')
@section('title', 'Editar preparação')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Editar preparação</h1><p class="page-subtitle">{{ $preparation->name }}</p></div></div>
    <form class="form-card space-y-6" method="POST" action="{{ route('preparations.update', $preparation) }}">@csrf @method('PUT') @include('preparations._form')</form>
@endsection
