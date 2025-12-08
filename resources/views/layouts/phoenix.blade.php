<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Phoenix Command Center')</title>
    
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js para interatividade -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body { font-family: 'Inter', sans-serif; }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-900 text-slate-300 antialiased" x-data="{ sidebarOpen: true }">

<div class="flex h-screen overflow-hidden">
    <!-- Sidebar -->
    <aside 
        x-show="sidebarOpen" 
        @click.away="sidebarOpen = false"
        class="w-64 flex-shrink-0 bg-slate-800/50 border-r border-slate-700/50 p-4 flex flex-col justify-between transition-all duration-300"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="-translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-300"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="-translate-x-full">
        
        <div>
            <!-- Logo -->
            <div class="flex items-center space-x-2 text-white mb-10">
                <svg class="h-8 w-8 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M12 6a2 2 0 100-4 2 2 0 000 4zm0 14a2 2 0 100-4 2 2 0 000 4zm6-8a2 2 0 100-4 2 2 0 000 4zm-12 0a2 2 0 100-4 2 2 0 000 4z"/></svg>
                <span class="text-xl font-bold">Phoenix</span>
            </div>
            
            <!-- Menu -->
            <nav class="space-y-2">
                <a href="#" class="flex items-center space-x-3 px-3 py-2 bg-cyan-500/20 text-white rounded-lg">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
                    <span>Dashboard</span>
                </a>
                <a href="#" class="flex items-center space-x-3 px-3 py-2 text-slate-400 hover:bg-slate-700/50 rounded-lg">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span>Agendador</span>
                </a>
                 <a href="#" class="flex items-center space-x-3 px-3 py-2 text-slate-400 hover:bg-slate-700/50 rounded-lg">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Logs</span>
                </a>
            </nav>
        </div>

        <!-- User Profile -->
        <div class="border-t border-slate-700 pt-4">
             <a href="#" class="flex items-center space-x-3 group">
                <img class="h-10 w-10 rounded-full object-cover" src="https://ui-avatars.com/api/?name=Admin+User&background=059669&color=fff" alt="User Avatar">
                <div>
                    <p class="font-semibold text-white">Admin User</p>
                    <p class="text-sm text-slate-400 group-hover:text-cyan-400">Ver Perfil</p>
                </div>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Header -->
        <header class="bg-slate-800/30 border-b border-slate-700/50 p-4 flex items-center justify-between">
            <!-- Mobile Menu Toggle & Breadcrumbs -->
            <div class="flex items-center space-x-4">
                <button @click.stop="sidebarOpen = !sidebarOpen" class="text-slate-400 hover:text-white">
                     <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"/></svg>
                </button>
                <div class="text-sm text-slate-400">
                    <span class="font-semibold text-slate-200">Admin</span> / Automações
                </div>
            </div>
            
            <!-- Header Actions -->
            <div class="flex items-center space-x-4">
                <button class="text-slate-400 hover:text-white">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </button>
                 <button class="text-slate-400 hover:text-white">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </button>
            </div>
        </header>

        <!-- Page Content -->
        <main class="flex-1 overflow-y-auto p-6 lg:p-8">
            @yield('content')
        </main>
    </div>
</div>

<!-- ApexCharts for Sparklines -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
@stack('scripts')
</body>
</html>