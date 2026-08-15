<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0f4c5c">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
        <title>{{ config('app.name', 'AR Sistemas - Gestión') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=source-sans-3:400,500,600,700|ibm-plex-sans:600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            body { font-family: 'Source Sans 3', sans-serif; }
            .ar-brand { font-family: 'IBM Plex Sans', sans-serif; }
        </style>
    </head>
    <body class="antialiased">
        <div class="ar-shell flex min-h-screen flex-col items-center justify-center px-4 py-10">
            <div class="mb-6 text-center">
                <div class="ar-brand text-2xl">AR Sistemas</div>
                <div class="ar-muted text-sm">Gestión personal y profesional</div>
            </div>
            <div class="ar-card w-full max-w-md p-6">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
