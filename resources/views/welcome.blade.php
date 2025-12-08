<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Automações</title>
    
    <!-- Fonts -->
    <link href="https://fonts.bunny.net/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS via CDN para um design moderno e rápido -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Nunito', sans-serif;
        }
        /* Animação simples para o ícone de 'running' */
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 antialiased">

    <!-- Conteúdo Principal -->
    <main class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto">

        <!-- Cabeçalho da Página -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Automation Control Center</h1>
            <p class="text-gray-600 mt-1">Monitore e gerencie todas as suas automações em um só lugar.</p>
        </div>

        <!-- Seção 1: Visão Geral (Stats Cards) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Card: Em Execução -->
            <div class="bg-white p-6 rounded-xl shadow-lg flex items-center space-x-4 transition-transform hover:scale-105">
                <div class="bg-blue-100 p-3 rounded-full">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Em Execução</p>
                    <p class="text-2xl font-bold">{{ $stats['running'] }}</p>
                </div>
            </div>
            <!-- Card: Execuções Hoje -->
            <div class="bg-white p-6 rounded-xl shadow-lg flex items-center space-x-4 transition-transform hover:scale-105">
                <div class="bg-green-100 p-3 rounded-full">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Execuções Hoje</p>
                    <p class="text-2xl font-bold">{{ $stats['executed_today'] }}</p>
                </div>
            </div>
            <!-- Card: Taxa de Sucesso -->
            <div class="bg-white p-6 rounded-xl shadow-lg flex items-center space-x-4 transition-transform hover:scale-105">
                <div class="bg-yellow-100 p-3 rounded-full">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">Taxa de Sucesso (24h)</p>
                    <p class="text-2xl font-bold">{{ $stats['success_rate'] }}%</p>
                </div>
            </div>
            <!-- Card: Próxima Agendada -->
            <div class="bg-white p-6 rounded-xl shadow-lg flex items-center space-x-4 transition-transform hover:scale-105">
                <div class="bg-purple-100 p-3 rounded-full">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">{{ $stats['next_automation']['name'] }}</p>
                    <p class="text-lg font-bold">em {{ $stats['next_automation']['in_minutes'] }} min</p>
                </div>
            </div>
        </div>

        <!-- Seção 2: Lista de Automações -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
            <div class="p-6 border-b">
                <h2 class="text-xl font-semibold">Painel de Controle de Automações</h2>
            </div>
            <div class="divide-y divide-gray-200">
                @foreach($automations as $automation)
                    <div class="p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between hover:bg-gray-50 transition-colors">
                        <div class="flex items-center space-x-4 mb-3 sm:mb-0">
                            <!-- Status Indicator -->
                            <span class="text-2xl font-bold 
                                @if($automation['status'] == 'active' || $automation['status'] == 'scheduled') text-green-500 
                                @elseif($automation['status'] == 'failed') text-red-500 
                                @else text-blue-500 @endif">●</span>
                            <div>
                                <p class="font-semibold">{{ $automation['name'] }}</p>
                                <p class="text-sm text-gray-500">{{ $automation['description'] }}</p>
                                <p class="text-xs text-gray-400 mt-1">Última Execução: {{ $automation['last_run'] }} ({{ $automation['result'] }})</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2 w-full sm:w-auto">
                            <!-- Botão de Ação -->
                            @if($automation['status'] == 'running')
                                <button class="w-full bg-gray-400 text-white font-bold py-2 px-4 rounded-lg flex items-center justify-center cursor-not-allowed">
                                    <svg class="w-5 h-5 mr-2 spinner" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h5M20 20v-5h-5"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 9a9 9 0 0114.13-6.36M20 15a9 9 0 01-14.13 6.36"></path></svg>
                                    Executando...
                                </button>
                            @else
                                <form action="#" method="POST" class="w-full">
                                    @csrf
                                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors">
                                        ▶️ Iniciar Agora
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Seção 3: Histórico Recente -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b">
                <h2 class="text-xl font-semibold">Histórico de Execuções</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="py-3 px-6 text-left font-semibold text-gray-600">Automação</th>
                            <th class="py-3 px-6 text-left font-semibold text-gray-600">Início</th>
                            <th class="py-3 px-6 text-left font-semibold text-gray-600">Duração</th>
                            <th class="py-3 px-6 text-left font-semibold text-gray-600">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($history as $log)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="py-4 px-6 font-medium">{{ $log['name'] }}</td>
                                <td class="py-4 px-6 text-gray-600">{{ $log['start_time'] }}</td>
                                <td class="py-4 px-6 text-gray-600">{{ $log['duration'] }}</td>
                                <td class="py-4 px-6">
                                    @if($log['status'] == 'Success')
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Sucesso</span>
                                    @elseif($log['status'] == 'Failed')
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Falha</span>
                                    @else
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Executando</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </main>

</body>
</html>