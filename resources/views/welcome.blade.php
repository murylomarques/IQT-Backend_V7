@extends('layouts.phoenix')

@section('title', 'Dashboard de Automações')

@section('content')
<!-- Cabeçalho da Página -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
    <div>
        <h1 class="text-3xl font-bold text-white">Dashboard de Automações</h1>
        <p class="text-slate-400 mt-1">Visão geral do sistema e status das execuções.</p>
    </div>
    <div class="mt-4 md:mt-0">
        <button class="bg-cyan-500 hover:bg-cyan-600 text-white font-bold py-2 px-4 rounded-lg flex items-center space-x-2 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            <span>Nova Automação</span>
        </button>
    </div>
</div>

<!-- Seção 1: Visão Geral (Stats Cards com Glassmorphism) -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    @php
        $statsCards = [
            ['title' => 'Em Execução', 'value' => $stats['running'], 'icon' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>', 'color' => 'blue'],
            ['title' => 'Execuções (24h)', 'value' => $stats['executed_today'], 'icon' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>', 'color' => 'green', 'sparkline' => 'executedChart'],
            ['title' => 'Taxa de Sucesso', 'value' => $stats['success_rate'].'%', 'icon' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>', 'color' => 'amber'],
            ['title' => 'Falhas (24h)', 'value' => 3, 'icon' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg>', 'color' => 'red', 'sparkline' => 'failedChart']
        ];
    @endphp

    @foreach($statsCards as $card)
    <div class="glassmorphism rounded-xl p-5 text-white">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-300">{{ $card['title'] }}</p>
            <div class="text-{{ $card['color'] }}-400">{!! $card['icon'] !!}</div>
        </div>
        <div class="flex items-end justify-between mt-2">
            <p class="text-3xl font-bold">{{ $card['value'] }}</p>
            @if(isset($card['sparkline']))
            <div id="{{ $card['sparkline'] }}" class="w-24 h-10 -mb-2 -mr-3"></div>
            @endif
        </div>
    </div>
    @endforeach
</div>


<!-- Seção 2: Painel de Controle de Automações -->
<div class="bg-slate-800/50 border border-slate-700/50 rounded-xl shadow-lg overflow-hidden">
    <div class="p-4 sm:p-6 border-b border-slate-700 flex flex-col sm:flex-row justify-between items-start sm:items-center">
        <h2 class="text-xl font-semibold text-white mb-3 sm:mb-0">Lista de Automações</h2>
        <div class="flex items-center space-x-2">
            <input type="text" placeholder="Buscar automação..." class="bg-slate-700/50 border border-slate-600 rounded-lg px-3 py-1.5 text-sm w-full sm:w-auto focus:ring-cyan-500 focus:border-cyan-500">
            <button class="text-slate-300 border border-slate-600 hover:bg-slate-700 rounded-lg px-3 py-1.5 text-sm">Filtros</button>
        </div>
    </div>
    
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="border-b border-slate-700">
                <tr>
                    <th class="py-3 px-6 text-left font-semibold text-slate-400">Status</th>
                    <th class="py-3 px-6 text-left font-semibold text-slate-400">Automação</th>
                    <th class="py-3 px-6 text-left font-semibold text-slate-400">Última Execução</th>
                    <th class="py-3 px-6 text-left font-semibold text-slate-400">Duração Média</th>
                    <th class="py-3 px-6 text-right font-semibold text-slate-400">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700/50">
                @foreach($automations as $automation)
                    <tr class="hover:bg-slate-700/20 transition-colors">
                        <td class="py-4 px-6">
                            @php
                                $statusClasses = [
                                    'running' => 'bg-blue-500/20 text-blue-300 border border-blue-500/30',
                                    'active' => 'bg-green-500/20 text-green-300 border border-green-500/30',
                                    'scheduled' => 'bg-green-500/20 text-green-300 border border-green-500/30',
                                    'failed' => 'bg-red-500/20 text-red-300 border border-red-500/30',
                                ];
                                $statusText = [
                                    'running' => 'Em Execução', 'active' => 'Ativa', 'scheduled' => 'Agendada', 'failed' => 'Com Falha',
                                ];
                            @endphp
                            <span class="px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-full {{ $statusClasses[$automation['status']] ?? '' }}">
                                {{ $statusText[$automation['status']] ?? 'Desconhecido' }}
                            </span>
                        </td>
                        <td class="py-4 px-6 font-medium text-white">{{ $automation['name'] }}</td>
                        <td class="py-4 px-6 text-slate-400">{{ $automation['last_run'] }} <span class="text-xs">({{ $automation['result'] }})</span></td>
                        <td class="py-4 px-6 text-slate-400">~ 2m 15s</td>
                        <td class="py-4 px-6 text-right">
                             <button class="text-cyan-400 hover:text-white font-semibold">Iniciar</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const commonOptions = {
        chart: { type: 'area', sparkline: { enabled: true } },
        stroke: { curve: 'smooth', width: 2 },
        fill: { opacity: 0.3 },
        yaxis: { min: 0 },
        xaxis: { crosshairs: { width: 1 } },
        tooltip: {
            fixed: { enabled: false },
            x: { show: false },
            y: {
                title: {
                    formatter: (seriesName) => 'Execuções'
                }
            },
            marker: { show: false }
        }
    };

    // Sparkline Chart 1
    var executedChart = new ApexCharts(document.querySelector("#executedChart"), {
        ...commonOptions,
        series: [{ name: 'Execuções', data: [31, 40, 28, 51, 42, 109, 100] }],
        colors: ['#22c55e'] // green-500
    });
    executedChart.render();
    
    // Sparkline Chart 2
    var failedChart = new ApexCharts(document.querySelector("#failedChart"), {
        ...commonOptions,
        series: [{ name: 'Falhas', data: [1, 2, 0, 3, 1, 0, 2] }],
        colors: ['#ef4444'] // red-500
    });
    failedChart.render();
});
</script>
@endpush