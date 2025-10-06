<?php

namespace App\Http\Controllers\Api;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\Agenda; // Use o seu modelo Agenda
use Illuminate\Http\Request;
use Carbon\Carbon; // Importe a classe Carbon para trabalhar com datas

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user(); // Pega o fiscal autenticado via token

        // Define a data de hoje para o filtro
        $today = Carbon::today();

        // --- Lógica para Contagem de Pendentes ---
        // Agendas do fiscal, para hoje, com statusAgendamento diferente de Concluído
        $pendentesCount = Agenda::where('fiscal_id', $user->id)
                                  ->whereDate('data_agendamento', $today)
                                  ->where('statusAgendamento', '!=', 'Concluído')
                                  ->count();

        // --- Lógica para Contagem de Realizadas (Concluídas) ---
        // Agendas do fiscal, para hoje, com statusAgendamento igual a Concluído
        $realizadasCount = Agenda::where('fiscal_id', $user->id)
                                   ->whereDate('data_agendamento', $today)
                                   ->where('statusAgendamento', 'Concluído')
                                   ->count();

        // --- Lógica para a Lista de Pendentes ---
        // Busca a lista de agendas que correspondem ao critério de pendente
        $agendasPendentes = Agenda::where('fiscal_id', $user->id)
                                    ->whereDate('data_agendamento', $today)
                                    ->where('statusAgendamento', '!=', 'Concluído')
                                    ->orderBy('hora_agendamento', 'asc') // Ordena pela hora
                                    ->get();
        // DEBUG: isso vai aparecer no terminal ou arquivo de log
        Log::info('Pendentes Count: ' . $pendentesCount);
        Log::info('Realizadas Count: ' . $realizadasCount);
        Log::info('Agendas Pendentes: ' . $agendasPendentes->toJson());
            // Retorna o JSON com os dados corretos
        return response()->json([
            'pendentes_count' => $pendentesCount,
            'realizadas_count' => $realizadasCount,
            'agendas_pendentes' => $agendasPendentes // A chave agora é 'agendas_pendentes'
        ]);
    }
}
