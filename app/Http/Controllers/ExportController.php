<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vistoria;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\VistoriaSeguranca; // Importe o Model


class ExportController extends Controller
{
    public function exportQualidade(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date'   => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate   = Carbon::parse($validated['end_date'])->endOfDay();
        
        // ==========================================================
        // ===== PASSO 1: DEFINIR TODAS AS COLUNAS DO CHECKLIST =====
        // ==========================================================
        // Use exatamente as mesmas 'keys' do seu frontend (checklistData.js)
       $checklistKeys = [
            // EXTERNA + COMPLETA
            'identificacao_cto',
            'identificacao_endereco',
            'conector_anilha',
            'acomodacao_drop_cto',
            'organizacao_drop_cto',
            'poste_cto_equipado',
            'tecnico_passou_drop',
            'altura_drop_rede',
            'lancamento_drop_lado',
            'drop_segue_ramal',
            'meia_lua_drop',
            'poste_passagem_equipado',
            'esticadores_corretos',
            'equipagem_poste_cliente',

            // INTERNA / COMPLETA
            'equipagem_fachada_cliente',
            'passagem_drop_externa',
            'passagem_drop_interna',
            'perda_sinal_cto_onu',
            'local_instalacao_equipamentos',
            'tecnico_manteve_limpo',

            // CONSULTIVAS (SEM FOTO)
            'explicacao_wifi',
            'tecnico_testes_produtos',
            'teste_velocidade_pontos',
            'cliente_app_desktop',

            'necessita_retorno',
        ];


        // Cria cabeçalhos dinâmicos para o checklist (ex: "Status - identificacao_cto")
        $checklistHeaders = [];
        foreach ($checklistKeys as $key) {
            $checklistHeaders[] = 'Status - ' . $key;
            $checklistHeaders[] = 'Obs - ' . $key;
        }
        
        // Define o nome do arquivo e os cabeçalhos do CSV
        $fileName = 'vistorias_qualidade.csv';
        $headers = array_merge([
            'ID Vistoria', 'Data', 'Tipo', 'Fiscal', 'Empresa', 'SA', 'Cliente',
            'Endereço', 'Técnico', 'Observações Gerais', 'Status do Laudo'
        ], $checklistHeaders); // Junta os cabeçalhos principais com os do checklist

        $response = new StreamedResponse(function() use ($headers, $startDate, $endDate, $checklistKeys) {
            
            $handle = fopen('php://output', 'w');

            // ==========================================================
            // ======== PASSO 2: CORRIGIR CARACTERES ESPECIAIS ========
            // ==========================================================
            // Adiciona o BOM (Byte Order Mark) para o Excel entender UTF-8
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            
            fputcsv($handle, $headers);

            Vistoria::with(['fiscal.empresa', 'agenda', 'checklistItens'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->chunk(100, function ($vistorias) use ($handle, $checklistKeys) {
                    
                    foreach ($vistorias as $vistoria) {
                        
                        $mainData = [
                            $vistoria->id, $vistoria->created_at->format('d/m/Y H:i'),
                            $vistoria->tipo, $vistoria->fiscal?->nome ?? 'N/A',
                            $vistoria->fiscal?->empresa?->nome ?? 'N/A', $vistoria->agenda?->numero_compromisso ?? 'N/A',
                            $vistoria->agenda?->nome_conta ?? 'N/A', $vistoria->agenda?->endereco ?? 'N/A',
                            $vistoria->agenda?->nome_tecnico ?? 'N/A', $vistoria->observacoes_gerais ?? '',
                            $vistoria->status_laudo ?? 'N/A',
                        ];

                        // ==========================================================
                        // ======= PASSO 3: TRANSFORMAR ITENS EM COLUNAS ==========
                        // ==========================================================
                        // Primeiro, transforma a coleção de itens em um mapa (array associativo) para busca rápida
                        $itemsMap = $vistoria->checklistItens->keyBy('item_key');
                        
                        $checklistData = [];
                        // Agora, itera sobre a lista fixa de perguntas
                        foreach ($checklistKeys as $key) {
                            // Se a resposta para esta pergunta existir na vistoria, usa os dados
                            if (isset($itemsMap[$key])) {
                                $checklistData[] = $itemsMap[$key]->status;
                                $checklistData[] = $itemsMap[$key]->observacao ?? '';
                            } else {
                                // Se não houver resposta, preenche com colunas vazias
                                $checklistData[] = '';
                                $checklistData[] = '';
                            }
                        }
                        
                        // Junta os dados principais com os dados do checklist e escreve a linha no CSV
                        fputcsv($handle, array_merge($mainData, $checklistData));
                    }
                });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');

        return $response;
    }


    public function exportSeguranca(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $fileName = 'vistorias_seguranca.csv';

        // ✅ Carrega o inspetor e pega só os campos necessários (inclui "nome")
        $vistorias = VistoriaSeguranca::with([
                'inspetor:id,nome',
                'regional:id,nome',
                'empresa:id,nome',
            ])
            ->whereBetween('created_at', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $headers = [
            'Content-type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0'
        ];

        $columns = [
            'ID', 'Data', 'Inspetor', 'Regional', 'Cidade', 'Técnico', 'CPF Técnico', 'Empresa',
            'Supervisor', 'Placa', 'Despache', 'Técnico no Local', 'Atividade Externa',
            'Uso Capacete', 'Uso Cinto', 'Uso Talabarte', 'Uso Botas', 'Escada Estável',
            'Escada Amarrada', 'Cones Sinalização', 'Escada Bom Estado', 'Observações'
        ];

        $callback = function () use ($vistorias, $columns) {
            $file = fopen('php://output', 'w');

            // ✅ BOM UTF-8 pro Excel
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($file, $columns);

            foreach ($vistorias as $vistoria) {
                $row = [
                    $vistoria->id,
                    optional($vistoria->created_at)->format('d/m/Y H:i:s') ?? '',
                    // ✅ CORRIGIDO: user tem "nome", não "name"
                    $vistoria->inspetor?->nome ?? 'N/A',
                    $vistoria->regional?->nome ?? 'N/A',
                    $vistoria->cidade ?? '',
                    $vistoria->nome_tecnico ?? '',
                    $vistoria->cpf_tecnico ?? '',
                    $vistoria->empresa?->nome ?? 'N/A',
                    $vistoria->nome_supervisor ?? '',
                    $vistoria->placa ?? '',
                    $vistoria->modo_despache ?? '',
                    $vistoria->tecnico_no_local ?? '',
                    $vistoria->atividade_externa ?? '',
                    $vistoria->uso_capacete ?? '',
                    $vistoria->uso_cinto ?? '',
                    $vistoria->uso_talabarte ?? '',
                    $vistoria->uso_botas ?? '',
                    $vistoria->escada_estavel ?? '',
                    $vistoria->escada_amarrada ?? '',
                    $vistoria->sinalizacao_cones ?? '',
                    $vistoria->escada_bom_estado ?? '',
                    $vistoria->observacoes ?? '',
                ];

                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

}