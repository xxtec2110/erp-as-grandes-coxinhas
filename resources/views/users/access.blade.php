@extends('layouts.app')
@section('title', 'Acessos de '.$managedUser->name)
@section('content')
<div class="page-header"><div><h1 class="page-title">Acessos de {{ $managedUser->name }}</h1><p class="page-subtitle">As exceções individuais prevalecem sobre os perfis.</p></div><a class="btn-secondary" href="{{ route('users.index') }}">Voltar</a></div>
<form method="POST" action="{{ route('users.access.update', $managedUser) }}" class="space-y-6">@csrf @method('PUT')
@if($canManagePermissions)<input type="hidden" name="_manage_permissions" value="1">@endif
@if($canManageLocations)<input type="hidden" name="_manage_locations" value="1">@endif
<section class="form-card"><div class="flex items-center justify-between gap-3"><h2 class="section-title">Perfis</h2>@unless($canManagePermissions)<span class="status-badge status-inactive">Somente leitura</span>@endunless</div><div class="mt-4 grid gap-3 sm:grid-cols-2">@foreach($roles as $role)<label class="flex gap-3"><input type="checkbox" @if($canManagePermissions) name="role_ids[]" @else disabled @endif value="{{ $role->id }}" @checked($managedUser->roles->contains($role))> {{ $role->label }}</label>@endforeach</div></section>
<section class="form-card"><div class="flex items-center justify-between gap-3"><h2 class="section-title">Acesso às unidades</h2>@unless($canManageLocations)<span class="status-badge status-inactive">Somente Admin Master</span>@endunless</div><label class="mt-4 flex gap-3 font-semibold"><input type="checkbox" @if($canManageLocations) name="all_locations_access" @else disabled @endif value="1" @checked($managedUser->all_locations_access)> Todas as unidades</label><div class="mt-4 grid gap-3 sm:grid-cols-2">@foreach($locations as $location)<label class="flex gap-3"><input type="checkbox" @if($canManageLocations) name="location_ids[]" @else disabled @endif value="{{ $location->id }}" @checked($managedUser->locations->contains($location))> {{ $location->name }}</label>@endforeach</div></section>
<section class="form-card"><div class="flex items-center justify-between gap-3"><h2 class="section-title">Exceções individuais</h2>@unless($canManagePermissions)<span class="status-badge status-inactive">Somente leitura</span>@endunless</div><div class="mt-4 space-y-4">@foreach($permissions->groupBy('group') as $group => $groupPermissions)<div><h3 class="font-bold text-stone-700">{{ $group }}</h3><div class="mt-2 grid gap-3 md:grid-cols-2">@foreach($groupPermissions as $permission) @php($pivot=$managedUser->permissions->firstWhere('id',$permission->id)?->pivot) <label><span class="form-label">{{ $permission->label }}</span><select class="form-input" @if($canManagePermissions) name="permission_overrides[{{ $permission->id }}]" @else disabled @endif><option value="inherit" @selected(!$pivot)>Herdar do perfil</option><option value="allow" @selected($pivot?->allowed === true)>Permitir</option><option value="deny" @selected($pivot?->allowed === false)>Negar</option></select></label>@endforeach</div></div>@endforeach</div></section>
<section class="card"><label class="form-label" for="default_location_id">Unidade padrão</label><select class="form-input" id="default_location_id" @if($canManageLocations) name="default_location_id" @else disabled @endif><option value="">Sem unidade padrão</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(old('default_location_id', $managedUser->default_location_id) == $location->id)>{{ $location->name }}</option>@endforeach</select><p class="mt-2 text-sm text-stone-500">Usada pelo agente quando nenhuma unidade for informada. Ela não concede acesso adicional.</p>@error('default_location_id')<p class="form-error">{{ $message }}</p>@enderror</section>
@if($canManagePermissions || $canManageLocations)<button class="btn-primary">Salvar acessos</button>@endif
</form>
@if($canManageDashboard)
<section class="mt-8 overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
    <div class="border-b border-stone-200 bg-stone-950 px-5 py-5 text-white sm:px-6">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-400">Dashboard</p>
        <h2 class="mt-1 text-xl font-black">Visibilidade do dashboard</h2>
        <p class="mt-2 max-w-3xl text-sm text-stone-300">Esta configuração apenas decide o que aparece no dashboard. Ela nunca concede acesso a módulos, dados financeiros ou outras unidades.</p>
    </div>

    @if($managedUser->is_super_admin)
        <div class="p-6 text-sm text-stone-700">O administrador master visualiza todos os widgets disponíveis. Essa regra de segurança não pode ser reduzida por preferência individual.</div>
    @else
        <form method="POST" action="{{ route('users.dashboard.update', $managedUser) }}" class="p-5 sm:p-6" data-dashboard-visibility-form>
            @csrf @method('PUT')
            <div class="mb-6 flex flex-wrap gap-2">
                <button type="button" class="btn-secondary text-xs" data-dashboard-set="show">Mostrar todos disponíveis</button>
                <button type="button" class="btn-secondary text-xs" data-dashboard-set="hide">Ocultar todos</button>
                <button type="button" class="btn-secondary text-xs" data-dashboard-set="inherit">Restaurar herança</button>
            </div>

            <div class="space-y-6">
                @foreach($dashboardWidgets->groupBy('group') as $group => $widgets)
                    <div class="rounded-xl border border-stone-200 p-4">
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="font-black text-stone-900">{{ mb_strtoupper($dashboardGroups[$group] ?? $group) }}</h3>
                            <div class="flex gap-2 text-xs">
                                <button type="button" class="font-bold text-amber-700" data-dashboard-group="{{ $group }}" data-dashboard-value="show">Mostrar grupo</button>
                                <span class="text-stone-300">|</span>
                                <button type="button" class="font-bold text-stone-600" data-dashboard-group="{{ $group }}" data-dashboard-value="hide">Ocultar grupo</button>
                            </div>
                        </div>
                        <div class="grid gap-3 lg:grid-cols-2">
                            @foreach($widgets as $widget)
                                <label class="rounded-xl border p-4 {{ $widget['available'] ? 'border-stone-200 bg-stone-50' : 'border-red-200 bg-red-50' }}">
                                    <span class="flex items-start justify-between gap-3">
                                        <span>
                                            <span class="block font-bold text-stone-900">{{ $widget['name'] }}</span>
                                            <span class="mt-1 block text-xs leading-5 text-stone-500">{{ $widget['description'] }}</span>
                                        </span>
                                        @if(!$widget['available'])<span class="rounded-full bg-red-100 px-2 py-1 text-[10px] font-black uppercase text-red-700">Bloqueado</span>@endif
                                    </span>
                                    <select class="form-input mt-3" name="widgets[{{ $widget['key'] }}]" data-dashboard-widget data-dashboard-group-name="{{ $group }}">
                                        <option value="inherit" @selected($widget['preference'] === 'inherit')>Herdar padrão</option>
                                        <option value="show" @selected($widget['preference'] === 'show') @disabled(!$widget['available'])>Mostrar</option>
                                        <option value="hide" @selected($widget['preference'] === 'hide')>Ocultar</option>
                                    </select>
                                    @if(!$widget['available'])
                                        <span class="mt-2 block text-xs font-semibold text-red-700">Faltam permissões funcionais: {{ implode(', ', $widget['missing_permissions']) }}.</span>
                                    @elseif($widget['sensitive'])
                                        <span class="mt-2 block text-xs font-semibold text-amber-700">Contém informação sensível.</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <button class="btn-primary mt-6">Salvar visibilidade</button>
        </form>
        <form method="POST" action="{{ route('users.dashboard.reset', $managedUser) }}" class="border-t border-stone-200 px-5 py-4 sm:px-6">
            @csrf @method('DELETE')
            <button class="text-sm font-bold text-stone-600 hover:text-stone-950">Restaurar dashboard padrão</button>
        </form>
    @endif
</section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-dashboard-visibility-form]');
    if (!form) return;
    const selects = [...form.querySelectorAll('[data-dashboard-widget]')];
    const setValue = (items, value) => items.forEach((select) => {
        if (value !== 'show' || !select.querySelector('option[value="show"]')?.disabled) select.value = value;
    });
    form.querySelectorAll('[data-dashboard-set]').forEach((button) => button.addEventListener('click', () => setValue(selects, button.dataset.dashboardSet)));
    form.querySelectorAll('[data-dashboard-group]').forEach((button) => button.addEventListener('click', () => setValue(selects.filter((select) => select.dataset.dashboardGroupName === button.dataset.dashboardGroup), button.dataset.dashboardValue)));
});
</script>
@endif
@endsection
