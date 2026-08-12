<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'As Grandes Coxinhas') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-amber-50 font-sans text-stone-900 antialiased">
        <main class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6 lg:px-8">
            @yield('content')
        </main>
    </body>
</html>
