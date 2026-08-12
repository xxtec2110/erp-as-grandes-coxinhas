@extends('layouts.app')
@section('title', 'Nova preparação')
@section('content')
    <div class="page-header"><div><h1 class="page-title">Nova preparação</h1><p class="page-subtitle">Cadastre os dados gerais; os ingredientes serão adicionados em seguida.</p></div></div>
    <form class="form-card space-y-6" method="POST" action="{{ route('preparations.store') }}">@csrf @include('preparations._form')</form>
@endsection
