@extends('layouts.guest')

@section('content')
    <div class="grid w-full max-w-5xl overflow-hidden rounded-3xl bg-white shadow-xl shadow-amber-950/10 lg:grid-cols-2">
        <section class="hidden bg-amber-600 p-12 text-white lg:flex lg:flex-col lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.25em] text-amber-100">ERP</p>
                <h1 class="mt-4 text-4xl font-bold leading-tight">As Grandes Coxinhas</h1>
                <p class="mt-5 max-w-sm text-lg leading-relaxed text-amber-50">
                    Gestão integrada para uma operação mais simples, segura e eficiente.
                </p>
            </div>

            <p class="text-sm text-amber-100">Acesso restrito a usuários autorizados.</p>
        </section>

        <section class="px-6 py-10 sm:px-10 sm:py-12 lg:px-12 lg:py-16">
            <div class="mx-auto max-w-md">
                <div class="lg:hidden">
                    <p class="text-sm font-semibold uppercase tracking-[0.25em] text-amber-700">ERP</p>
                    <h1 class="mt-2 text-2xl font-bold text-stone-900">As Grandes Coxinhas</h1>
                </div>

                <div class="mt-8 lg:mt-0">
                    <h2 class="text-2xl font-bold tracking-tight text-stone-900">Acesse sua conta</h2>
                    <p class="mt-2 text-sm text-stone-600">Informe seu e-mail e senha para continuar.</p>
                </div>

                <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-6">
                    @csrf

                    <div>
                        <label for="email" class="block text-sm font-semibold text-stone-800">E-mail</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            autocomplete="email"
                            inputmode="email"
                            required
                            autofocus
                            aria-describedby="@error('email') email-error @enderror"
                            class="mt-2 block min-h-12 w-full rounded-xl border border-stone-300 bg-white px-4 py-3 text-base text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-amber-600 focus:ring-4 focus:ring-amber-100"
                            placeholder="nome@empresa.com"
                        >
                        @error('email')
                            <p id="email-error" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-semibold text-stone-800">Senha</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                            aria-describedby="@error('password') password-error @enderror"
                            class="mt-2 block min-h-12 w-full rounded-xl border border-stone-300 bg-white px-4 py-3 text-base text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-amber-600 focus:ring-4 focus:ring-amber-100"
                            placeholder="Digite sua senha"
                        >
                        @error('password')
                            <p id="password-error" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <button
                        type="submit"
                        class="flex min-h-12 w-full items-center justify-center rounded-xl bg-amber-600 px-4 py-3 text-base font-bold text-white transition hover:bg-amber-700 focus:outline-none focus:ring-4 focus:ring-amber-200"
                    >
                        Entrar
                    </button>
                </form>
            </div>
        </section>
    </div>
@endsection
