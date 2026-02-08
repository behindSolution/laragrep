<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'LaraGrep Monitor')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900 font-sans min-h-screen">
    <nav class="bg-white border-b px-6 py-3 flex items-center gap-6">
        <span class="font-bold text-lg">LaraGrep Monitor</span>
        <a href="{{ route('laragrep.monitor.list') }}" class="text-sm {{ request()->routeIs('laragrep.monitor.list') ? 'text-indigo-600 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Logs</a>
        <a href="{{ route('laragrep.monitor.overview') }}" class="text-sm {{ request()->routeIs('laragrep.monitor.overview') ? 'text-indigo-600 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Overview</a>
    </nav>
    <main class="max-w-7xl mx-auto px-4 py-6">
        @yield('content')
    </main>
</body>
</html>
