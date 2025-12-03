<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vistoria;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\VistoriaSeguranca;

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
        
        // 1. Definição das Chaves
        $checklistKeys = [
            'identificacao_cto', 'identificacao_endereco', 'conector_anilha',
            'acomodacao_drop_cto', 'organizacao_drop_cto', 'poste_cto_equipado',
            'tecnico_passou_drop', 'altura_drop_rede', 'lancamento_drop_lado',
            'drop_segue_ramal', 'meia_lua_drop', 'esticadores_corretos',
            'equipagem_poste_cliente', 'necessita_retorno',
            'poste_passagem_equipado', 'poste_passagem_equipado_1',
            'poste_passagem_equipado_2', 'poste_passagem_equipado_3',
            'equipagem_fachada_cliente', 'passagem_drop_externa',
            'passagem_drop_interna', 'perda_sinal_cto_onu',
            'local_instalacao_equipamentos', 'tecnico_manteve_limpo',
            'explicacao_wifi', 'tecnico_testes_produtos',
            'teste_velocidade_pontos', 'cliente_app_desktop'
        ];

        // 2. Mapa de Gravidade (Baseado na sua lista)
        // Mapeamos as chaves do banco para o nível de gravidade solicitado
        $severityMap = [
            'identificacao_cto'             => 'GRAVE',
            'identificacao_endereco'        => 'GRAVE',
            'conector_anilha'               => 'LEVE',
            'acomodacao_drop_cto'           => 'LEVE',
            'organizacao_drop_cto'          => 'LEVE',
            'poste_cto_equipado'            => 'MODERADA',
            'tecnico_passou_drop'           => 'GRAVE', // Trajeto Drop
            'altura_drop_rede'              => 'MODERADA',
            'lancamento_drop_lado'          => 'LEVE',
            'drop_segue_ramal'              => 'GRAVE',
            'meia_lua_drop'                 => 'LEVE',
            'esticadores_corretos'          => 'MODERADA',
            'equipagem_poste_cliente'       => 'MODERADA',
            'necessita_retorno'             => 'GRAVE', // Assumido como Grave pois gera visita técnica
            'poste_passagem_equipado'       => 'MODERADA',
            'poste_passagem_equipado_1'     => 'MODERADA',
            'poste_passagem_equipado_2'     => 'MODERADA',
            'poste_passagem_equipado_3'     => 'MODERADA',
            'equipagem_fachada_cliente'     => 'MODERADA',
            'passagem_drop_externa'         => 'MODERADA',
            'passagem_drop_interna'         => 'MODERADA',
            'perda_sinal_cto_onu'           => 'GRAVE',
            'local_instalacao_equipamentos' => 'MODERADA',
            'tecnico_manteve_limpo'         => 'LEVE',
            'explicacao_wifi'               => 'LEVE',
            'tecnico_testes_produtos'       => 'LEVE',
            'teste_velocidade_pontos'       => 'LEVE',
            'cliente_app_desktop'           => 'LEVE'
        ];

        // Peso para cálculo (Quanto maior, mais grave)
        $severityRank = [
            'LEVE'     => 1,
            'MODERADA' => 2,
            'GRAVE'    => 3
        ];

        // Cria cabeçalhos do checklist
        $checklistHeaders = [];
        foreach ($checklistKeys as $key) {
            $checklistHeaders[] = 'Status - ' . $key;
            $checklistHeaders[] = 'Obs - ' . $key;
        }

        // 3. Cabeçalhos CSV
        // Adicionei "Status Calculado" e "Gravidade Máxima" logo após os dados da Vistoria
        $vistoriaHeaders = [
            'ID Vistoria', 'Data Vistoria', 'Tipo Vistoria', 
            'Fiscal Nome', 'Empresa Fiscal', 
            'Obs Gerais Vistoria', 'Status do Laudo (Sistema)',
            'Status do Laudo (Calculado)', 'Gravidade Máxima' // Novas Colunas
        ];

        $agendaHeaders = [
            'Agenda - ID', 'Agenda - Fiscal ID', 'Agenda - Data Agendamento',
            'Agenda - Hora Agendamento', 'Agenda - Período', 'Agenda - Observações',
            'Agenda - Status', 'Agenda - Status Agendamento', 'Agenda - Status Laudo',
            'Agenda - Tipo', 'Agenda - Original Atendimento ID', 'Agenda - Caso',
            'Agenda - Número Compromisso (SA)', 'Agenda - Cidade', 'Agenda - Data SA Concluída',
            'Agenda - Nome Técnico', 'Agenda - Empresa Técnico', 'Agenda - Tipo Trabalho',
            'Agenda - Status Caso', 'Agenda - CTO', 'Agenda - Porta',
            'Agenda - Nome Conta (Cliente)', 'Agenda - Endereço', 'Agenda - Telefone',
            'Agenda - Criado em', 'Agenda - Atualizado em', 'Agenda - Território'
        ];
        
        $fileName = 'vistorias_qualidade_com_gravidade.csv';
        $headers = array_merge($vistoriaHeaders, $agendaHeaders, $checklistHeaders); 

        $response = new StreamedResponse(function() use ($headers, $startDate, $endDate, $checklistKeys, $severityMap, $severityRank) {
            
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
            fputcsv($handle, $headers);

            Vistoria::with(['fiscal.empresa', 'agenda', 'checklistItens'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->chunk(100, function ($vistorias) use ($handle, $checklistKeys, $severityMap, $severityRank) {
                    
                    foreach ($vistorias as $vistoria) {
                        
                        // ===== LÓGICA DE CÁLCULO DE STATUS E GRAVIDADE =====
                        $itemsMap = $vistoria->checklistItens->keyBy('item_key');
                        
                        $statusCalculado = 'Conforme'; // Padrão inicial
                        $maxGravidadeVal = 0;          // 0 = Nenhuma
                        $gravidadeTexto  = '';         // Texto final (LEVE/MODERADA/GRAVE)

                        foreach ($checklistKeys as $key) {
                            if (isset($itemsMap[$key])) {
                                $statusItem = $itemsMap[$key]->status; // Ex: "Conforme", "Não Conforme", "N/A"
                                
                                // Verifica se é Não Conforme (ajuste a string conforme está no seu banco, ex: "Nao Conforme" ou "Reprovado")
                                // Estou assumindo que a string exata salva no banco é "Não Conforme"
                                if ($statusItem === 'Não Conforme') {
                                    $statusCalculado = 'Não Conforme';
                                    
                                    // Pega a gravidade definida no mapa, ou assume LEVE se não achar
                                    $gravidadeItem = $severityMap[$key] ?? 'LEVE';
                                    $pesoItem = $severityRank[$gravidadeItem] ?? 1;

                                    // Se este item for mais grave que o anterior encontrado, atualiza
                                    if ($pesoItem > $maxGravidadeVal) {
                                        $maxGravidadeVal = $pesoItem;
                                        $gravidadeTexto = $gravidadeItem;
                                    }
                                }
                            }
                        }

                        // Se estava tudo conforme, a gravidade fica vazia
                        if ($statusCalculado === 'Conforme') {
                            $gravidadeTexto = ''; 
                        }
                        // ===================================================

                        $vistoriaData = [
                            $vistoria->id,
                            $vistoria->created_at->format('d/m/Y H:i'),
                            $vistoria->tipo,
                            $vistoria->fiscal?->nome ?? 'N/A',
                            $vistoria->fiscal?->empresa?->nome ?? 'N/A',
                            $vistoria->observacoes_gerais ?? '',
                            $vistoria->status_laudo ?? 'N/A',
                            $statusCalculado, // Coluna Nova
                            $gravidadeTexto   // Coluna Nova
                        ];

                        $agendaData = [
                            $vistoria->agenda?->id ?? '',
                            $vistoria->agenda?->fiscal_id ?? '',
                            $vistoria->agenda?->data_agendamento ?? '',
                            $vistoria->agenda?->hora_agendamento ?? '',
                            $vistoria->agenda?->periodo ?? '',
                            $vistoria->agenda?->observacoes ?? '',
                            $vistoria->agenda?->status ?? '',
                            $vistoria->agenda?->statusAgendamento ?? '',
                            $vistoria->agenda?->statusLaudo ?? '',
                            $vistoria->agenda?->tipo ?? '',
                            $vistoria->agenda?->original_atendimento_id ?? '',
                            $vistoria->agenda?->caso ?? '',
                            $vistoria->agenda?->numero_compromisso ?? '',
                            $vistoria->agenda?->city ?? '',
                            $vistoria->agenda?->data_sa_concluida ?? '',
                            $vistoria->agenda?->nome_tecnico ?? '',
                            $vistoria->agenda?->empresa_tecnico ?? '',
                            $vistoria->agenda?->tipo_trabalho ?? '',
                            $vistoria->agenda?->status_caso ?? '',
                            $vistoria->agenda?->cto ?? '',
                            $vistoria->agenda?->porta ?? '',
                            $vistoria->agenda?->nome_conta ?? '',
                            $vistoria->agenda?->endereco ?? '',
                            $vistoria->agenda?->telefone ?? '',
                            $vistoria->agenda?->created_at ?? '',
                            $vistoria->agenda?->updated_at ?? '',
                            $vistoria->agenda?->territorio ?? '',
                        ];

                        $checklistData = [];
                        foreach ($checklistKeys as $key) {
                            if (isset($itemsMap[$key])) {
                                $checklistData[] = $itemsMap[$key]->status;
                                $checklistData[] = $itemsMap[$key]->observacao ?? '';
                            } else {
                                $checklistData[] = '';
                                $checklistData[] = '';
                            }
                        }
                        
                        fputcsv($handle, array_merge($vistoriaData, $agendaData, $checklistData));
                    }
                });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');

        return $response;
    }

    // Mantenha exportSeguranca abaixo...
   public function exportSeguranca(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $fileName = 'vistorias_seguranca.csv';

        $vistorias = VistoriaSeguranca::with(['inspetor', 'regional', 'empresa'])
            ->whereBetween('created_at', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ])
            ->get();

        $headers = [
            'Content-type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0'
        ];

        $columns = [ // Defina as colunas do seu CSV
            'ID', 'Data', 'Inspetor', 'Regional', 'Cidade', 'Técnico', 'CPF Técnico', 'Empresa',
            'Supervisor', 'Placa', 'Despache', 'Técnico no Local', 'Atividade Externa',
            'Uso Capacete', 'Uso Cinto', 'Uso Talabarte', 'Uso Botas', 'Escada Estável',
            'Escada Amarrada', 'Cones Sinalização', 'Escada Bom Estado', 'Observações'
        ];

        $callback = function() use($vistorias, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($vistorias as $vistoria) {
                $row = [
                    $vistoria->id,
                    $vistoria->created_at->format('d/m/Y H:i:s'),
                    $vistoria->inspetor->name ?? 'N/A',
                    $vistoria->regional->nome ?? 'N/A',
                    $vistoria->cidade,
                    $vistoria->nome_tecnico,
                    $vistoria->cpf_tecnico,
                    $vistoria->empresa->nome ?? 'N/A',
                    $vistoria->nome_supervisor,
                    $vistoria->placa,
                    $vistoria->modo_despache,
                    $vistoria->tecnico_no_local,
                    $vistoria->atividade_externa,
                    $vistoria->uso_capacete,
                    $vistoria->uso_cinto,
                    $vistoria->uso_talabarte,
                    $vistoria->uso_botas,
                    $vistoria->escada_estavel,
                    $vistoria->escada_amarrada,
                    $vistoria->sinalizacao_cones,
                    $vistoria->escada_bom_estado,
                    $vistoria->observacoes
                ];
                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}