<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Link expired</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-base-200 text-base-content antialiased">
        <main class="mx-auto flex max-w-xl flex-col gap-4 px-6 py-16">
            <h1 class="text-2xl font-semibold tracking-tight">This link has expired</h1>
            <p class="text-base-content/70">
                Facebook Marketplace export downloads are available for a limited time. Generate a new export from the home page if you still need files.
            </p>
            <div>
                <a href="/" class="btn btn-primary btn-sm">Back to home</a>
            </div>
        </main>
    </body>
</html>
