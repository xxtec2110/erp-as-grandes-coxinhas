@extends('layouts.app')
@section('title','Configurações financeiras')
@section('content')
<div class="page-header"><h1 class="page-title">Configurações financeiras</h1></div><div class="grid gap-6 lg:grid-cols-3">@foreach([['Contas financeiras','finance.accounts.store',$accounts],['Categorias','finance.categories.store',$categories],['Centros de custo','finance.centers.store',$centers]] as [$title,$route,$items])<section class="form-card"><h2 class="section-title">{{ $title }}</h2><form class="mt-4 space-y-3" method="POST" action="{{ route($route) }}">@csrf<input class="form-input" name="name" placeholder="Nome" required><input type="hidden" name="active" value="1">@if($route==='finance.accounts.store')<input class="form-input" name="type" value="bank" placeholder="Tipo">@endif<button class="btn-secondary">Adicionar</button></form><ul class="mt-4 space-y-2">@foreach($items as $item)<li>{{ $item->name }}</li>@endforeach</ul></section>@endforeach</div>
@endsection
