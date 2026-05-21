<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title inertia>{{ config('app.name', 'CCDI Account Portal') }}</title>

    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Favicons --}}
    <link rel="icon" type="image/png" href="/images/ccdilogo.png">
    <link rel="apple-touch-icon" href="/images/ccdilogo.png">

    @routes  {{-- 🔥 REQUIRED FOR ZIGGY --}}
    @vite(['resources/js/app.ts'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>