<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function index()
    {
        // --- DADOS DE EXEMPLO ---
        $stats = [
            'running' => 1,
            'executed_today' => 154,
            'success_rate' => 98,
            'next_automation' => [
                'name' => 'Sincronizar Pedidos',
                'in_minutes' => 15,
            ]
        ];

        $automations = [
            // ... (mesmos dados de automação da resposta anterior)
            [ 'id' => 1, 'name' => 'Sincronização de Clientes', 'description' => 'Sincroniza novos clientes do CRM.', 'status' => 'active', 'last_run' => 'Hoje às 08:45', 'result' => 'Sucesso' ],
            [ 'id' => 2, 'name' => 'Geração de Relatório Semanal', 'description' => 'Envia o relatório de vendas.', 'status' => 'scheduled', 'last_run' => 'Ontem às 17:00', 'result' => 'Sucesso' ],
            [ 'id' => 3, 'name' => 'Backup da Base de Dados', 'description' => 'Realiza o backup noturno.', 'status' => 'failed', 'last_run' => 'Hoje às 02:00', 'result' => 'Falhou' ],
            [ 'id' => 4, 'name' => 'Processamento de Pagamentos', 'description' => 'Processa a fila de pagamentos.', 'status' => 'running', 'last_run' => 'Hoje às 09:00', 'result' => 'Iniciada' ],
        ];
        
        $history = [
            // ... (mesmos dados de histórico da resposta anterior)
            ['id' => '#8451', 'name' => 'Sincronização de Clientes', 'start_time' => '08/12/2025 09:15:02', 'duration' => '45s', 'status' => 'Success'],
            ['id' => '#8450', 'name' => 'Processamento de Pagamentos', 'start_time' => '08/12/2025 09:00:10', 'duration' => 'Em andamento...', 'status' => 'Running'],
            ['id' => '#8449', 'name' => 'Backup da Base de Dados', 'start_time' => '08/12/2025 02:00:00', 'duration' => '1m 15s', 'status' => 'Failed'],
            ['id' => '#8448', 'name' => 'Geração de Relatório Semanal', 'start_time' => '07/12/2025 17:00:05', 'duration' => '5m 12s', 'status' => 'Success'],
        ];

        // IMPORTANTE: Aponte para a view 'welcome'
        return view('welcome', compact('stats', 'automations', 'history'));
    }
}