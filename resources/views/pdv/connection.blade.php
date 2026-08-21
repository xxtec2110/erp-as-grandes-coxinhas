@extends('layouts.app')
@section('title', $connection ? 'Editar GrandChef' : 'Configurar GrandChef')
@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <a class="text-sm font-bold text-amber-700" href="{{ route('pdv.index') }}">← Integrações</a>
            <h1 class="mt-2 text-3xl font-bold">{{ $connection ? 'Editar GrandChef' : 'Configurar GrandChef' }}</h1>
            <p class="mt-2 text-sm text-stone-600">As credenciais são criptografadas. Os valores atuais nunca são devolvidos ao navegador.</p>
        </div>

        <form method="POST" action="{{ $connection ? route('pdv.connections.update', $connection) : route('pdv.connections.store', $selectedLocation) }}" class="space-y-5 rounded-xl border bg-white p-5 shadow-sm">
            @csrf
            @if ($connection) @method('PUT') @endif

            <div>
                <label class="text-sm font-bold" for="location_id">Unidade</label>
                @if ($connection?->location_id)
                    <input type="hidden" name="location_id" value="{{ $connection->location_id }}">
                    <input class="mt-1 w-full rounded-lg border bg-stone-100 p-3" value="{{ $connection->location->name }}" disabled>
                @else
                    <select id="location_id" name="location_id" class="mt-1 w-full rounded-lg border p-3" required>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((int) old('location_id', $selectedLocation?->id) === $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                @endif
                @error('location_id')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="text-sm font-bold" for="name">Nome da integração</label>
                <input id="name" name="name" value="{{ old('name', $connection?->name ?? 'GrandChef') }}" class="mt-1 w-full rounded-lg border p-3" required maxlength="255">
                @error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="text-sm font-bold" for="endpoint">Endpoint GraphQL HTTPS</label>
                <input id="endpoint" type="url" name="endpoint" value="{{ old('endpoint', data_get($connection?->configuration, 'endpoint')) }}" class="mt-1 w-full rounded-lg border p-3" placeholder="https://.../graphql" required maxlength="2048" autocomplete="off">
                @error('endpoint')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="text-sm font-bold" for="bearer_token">Bearer token</label>
                <input id="bearer_token" type="password" name="bearer_token" value="" class="mt-1 w-full rounded-lg border p-3" autocomplete="new-password" maxlength="4096" placeholder="{{ $bearerCredentialConfigured ? 'Deixe vazio para manter a credencial existente' : 'Informe a credencial Bearer' }}">
                <p class="mt-1 text-xs text-stone-500">{{ $bearerCredentialConfigured ? 'Bearer configurado. Um campo vazio preserva o valor atual.' : 'Bearer ainda não configurado.' }}</p>
                @error('bearer_token')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="text-sm font-bold" for="device_token">Device token</label>
                <input id="device_token" type="password" name="device_token" value="" class="mt-1 w-full rounded-lg border p-3" autocomplete="new-password" maxlength="4096" placeholder="{{ $deviceCredentialConfigured ? 'Deixe vazio para manter a credencial existente' : 'Informe a credencial Device' }}">
                <p class="mt-1 text-xs text-stone-500">{{ $deviceCredentialConfigured ? 'Device configurado. Um campo vazio preserva o valor atual.' : 'Device ainda não configurado.' }}</p>
                @error('device_token')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>

            <label class="flex items-center gap-3 rounded-lg border p-3">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $connection?->enabled ?? false)) class="size-5 rounded border-stone-300">
                <span><strong>Ativa</strong><span class="block text-xs text-stone-500">Ativar exige unidade, endpoint, Bearer e Device.</span></span>
            </label>
            @error('enabled')<p class="text-sm text-red-700">{{ $message }}</p>@enderror

            <div class="flex flex-wrap gap-3">
                <button class="rounded-lg bg-stone-900 px-5 py-3 font-bold text-white">Salvar configuração</button>
                <a href="{{ route('pdv.index') }}" class="rounded-lg border px-5 py-3 font-bold">Cancelar</a>
            </div>
        </form>
    </div>
@endsection
