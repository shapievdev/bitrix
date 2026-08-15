<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>

    {{-- Библиотека портала: даёт BX24.resizeWindow, BX24.fitWindow, BX24.callMethod
         и прочее взаимодействие с интерфейсом Битрикс24 из фрейма. --}}
    <script src="//api.bitrix24.com/api/v1/"></script>

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
    @inertia
</body>
</html>