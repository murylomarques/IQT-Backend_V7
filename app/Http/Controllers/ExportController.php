<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vistoria;
use App\Models\VistoriaSeguranca;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $checklistKeys = [
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
            'poste_passagem_equipado_1',
            'poste_passagem_equipado_2',
            'poste_passagem_equipado_3',
            'esticadores_corretos',
            'equipagem_poste_cliente',
            'equipagem_fachada_cliente',
            'passagem_drop_externa',
            'passagem_drop_interna',
            'perda_sinal_cto_onu',
            'local_instalacao_equipamentos',
            'tecnico_manteve_limpo',
            'explicacao_wifi',
            'tecnico_testes_produtos',
            'teste_velocidade_pontos',
            'cliente_app_desktop',
            'necessita_retorno',
        ];

        // Classificação de gravidade (atualizada conforme lista de falhas)
        $pesoPorItem = [
            // FALHA -> GRAVIDADE (mapeado para item_key)
            'acomodacao_drop_cto'           => 'LEVE',     // ACOMODACAO_DROP_SAIDA_CTO_GROMMET_CAVIDADE
            'organizacao_drop_cto'          => 'LEVE',     // ACOMODACAO_DROP_SAIDA_FITA_HELLERMAN
            'altura_drop_rede'              => 'MODERADA', // ALTURA_DROP_ACIMA_REDE_DESKTOP
            'cliente_app_desktop'           => 'LEVE',     // APP_DESKTOP
            'explicacao_wifi'               => 'LEVE',     // CLIENTE_CIENTE_REDE_2G_5G
            'conector_anilha'               => 'LEVE',     // CONECTOR_CTO_TEM_ANILHA
            'passagem_drop_externa'         => 'GRAVE',    // ENTRADA_CABEAMENTO_CASA_CLIENTE
            'equipagem_poste_cliente'       => 'LEVE',     // EQUIPAGEM_POSTE_CASA_CLIENTE
            'equipagem_fachada_cliente'     => 'MODERADA', // EQUIPAGEM_POSTE_FACHADA_CASA_CLIENTE
            'poste_passagem_equipado'       => 'LEVE',     // EQUIPAGEM_POSTE_ITERMEDIARIO
            'poste_passagem_equipado_1'     => 'LEVE',
            'poste_passagem_equipado_2'     => 'LEVE',
            'poste_passagem_equipado_3'     => 'LEVE',
            'esticadores_corretos'          => 'LEVE',     // ESTICADORES_INSTALADO_FORMA_CORRETA
            'identificacao_cto'             => 'GRAVE',    // IDENTIFICACAO_CTO
            'lancamento_drop_lado'          => 'LEVE',     // LANCAMENTO_DROP_PARA_LADO_FORA_POSTE
            'local_instalacao_equipamentos' => 'MODERADA', // LOCAL_INSTALACAO_ONU_ROTEADOR
            'passagem_drop_interna'         => 'MODERADA', // PASSAGEM_CABEAMENTO_INTERNO
            'poste_cto_equipado'            => 'LEVE',     // POSTE_CTO_EQUIPADO
            'perda_sinal_cto_onu'           => 'GRAVE',    // SINAL_ONU_CTO
            'tecnico_testes_produtos'       => 'LEVE',     // TECNICO_EFETUOU_TESTE_PRODUTO
            'tecnico_manteve_limpo'         => 'LEVE',     // TECNICO_MANTEVE_LOCAL_LIMPO
            'tecnico_passou_drop'           => 'GRAVE',    // TRAJETO_DROP_CABO_DROP
            'meia_lua_drop'                 => 'LEVE',     // TRAJETO_DROP_MEIA_LUA
            'drop_segue_ramal'              => 'LEVE',     // TRAJETO_DROP_SEGUINDO_RAMAL_ENERGIA

            // Itens sem correspondência explícita na lista (mantidos como LEVE)
            'teste_velocidade_pontos'       => 'LEVE',
            'necessita_retorno'             => 'LEVE',
            'identificacao_endereco'        => 'LEVE',
        ];

        $checklistHeaders = [];
        foreach ($checklistKeys as $key) {
            $checklistHeaders[] = "Status - {$key}";
            $checklistHeaders[] = "Obs - {$key}";
            $checklistHeaders[] = "StatusCorreção - {$key}";
            $checklistHeaders[] = "ObsCorreção - {$key}";
        }

        $headers = array_merge([
            'ID Vistoria',
            'Data Vistoria',
            'Tipo Vistoria',
            'Status do Laudo',

            'Nome do Fiscal',

            // Empresa do técnico (agenda.empresa_tecnico)
            'Empresa do Técnico',

            'Numero Compromisso',
            'Cliente',
            'Endereço',
            'Técnico',

            'Numero Cliente (ag_servicos)',
            'Protocolo (ag_servicos)',
            'Nome Cliente (ag_servicos)',
            'Cidade (ag_servicos)',
            'Regional (ag_servicos)',
            'Data Agendamento (ag_servicos)',
            'Tipo Serviço (ag_servicos)',
            'Periodo SF (ag_servicos)',
            'Data Disparo (ag_servicos)',
            'Data Antecipacao (ag_servicos)',
            'Identificador (ag_servicos)',
            'Status (ag_servicos)',
            'Fixado (ag_servicos)',
            'Endereco Atendimento (ag_servicos)',
            'Territorio N (ag_servicos)',
            'Status Retirada (ag_servicos)',
            'Data Envio (ag_servicos)',

            'Observações Gerais',
            'Retorno Técnico',
            'Metros de Drop',

            'Resultado da Vistoria',
            'Classificação (Leve/Moderada/Grave)',
            'Status do Backlog',
        ], $checklistHeaders);

        $fileName = 'vistorias_qualidade_completo.csv';

        $response = new StreamedResponse(function () use (
            $headers, $startDate, $endDate, $checklistKeys, $pesoPorItem
        ) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 pro Excel
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, $headers);

            $agServicosMap = DB::table('agendamentos_servicos')
                ->select([
                    'numero_compromisso',
                    'numero_cliente',
                    'protocolo',
                    'nome_cliente',
                    'cidade',
                    'regional',
                    'data_agendamento',
                    'tipo_servico',
                    'periodo_sf',
                    'data_disparo',
                    'data_antecipacao',
                    'identificador',
                    'status',
                    'fixado',
                    'endereco_atendimento',
                    'territorio_n',
                    'status_retirada',
                    'data_envio',
                ])
                ->get()
                ->keyBy('numero_compromisso');

            Vistoria::with(['fiscal', 'agenda', 'checklistItens'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->chunk(200, function ($vistorias) use ($handle, $checklistKeys, $pesoPorItem, $agServicosMap) {
                    foreach ($vistorias as $vistoria) {
                        $numeroCompromisso = $vistoria->agenda?->numero_compromisso ?? null;
                        $ag = $numeroCompromisso ? ($agServicosMap[$numeroCompromisso] ?? null) : null;

                        $itemsMap = $vistoria->checklistItens->keyBy('item_key');

                        $temNaoConforme = false;
                        $severidadeRank = ['LEVE' => 1, 'MODERADA' => 2, 'GRAVE' => 3];
                        $maiorSeveridade = null;

                        $naoConformes = [];
                        foreach ($itemsMap as $itemKey => $item) {
                            if (($item->status ?? '') === 'Não Conforme') {
                                $temNaoConforme = true;
                                $naoConformes[] = $item;

                                $peso = $pesoPorItem[$itemKey] ?? null;
                                if ($peso) {
                                    if ($maiorSeveridade === null) {
                                        $maiorSeveridade = $peso;
                                    } else {
                                        if (($severidadeRank[$peso] ?? 0) > ($severidadeRank[$maiorSeveridade] ?? 0)) {
                                            $maiorSeveridade = $peso;
                                        }
                                    }
                                }
                            }
                        }

                        $resultadoVistoria = $temNaoConforme ? 'Irregular' : 'Conforme';
                        $classificacao = $temNaoConforme ? ($maiorSeveridade ?? 'LEVE') : 'LEVE';

                        $statusBacklog = 'Sem Backlog';
                        if ($temNaoConforme) {
                            $todosAprovados = true;
                            foreach ($naoConformes as $nc) {
                                if (($nc->status_correcao ?? 'Pendente') !== 'Aprovado') {
                                    $todosAprovados = false;
                                    break;
                                }
                            }
                            $statusBacklog = $todosAprovados ? 'Backlog Resolvido' : 'Backlog Aberto';
                        }

                        $empresaTecnico = $vistoria->agenda?->empresa_tecnico ?? 'N/A';

                        $mainData = [
                            $vistoria->id,
                            optional($vistoria->created_at)->format('d/m/Y H:i'),
                            $vistoria->tipo ?? 'N/A',
                            $vistoria->status_laudo ?? 'N/A',

                            $vistoria->fiscal?->nome ?? 'N/A',
                            $empresaTecnico,

                            $numeroCompromisso ?? 'N/A',
                            $vistoria->agenda?->nome_conta ?? 'N/A',
                            $vistoria->agenda?->endereco ?? 'N/A',
                            $vistoria->agenda?->nome_tecnico ?? 'N/A',

                            $ag->numero_cliente ?? '',
                            $ag->protocolo ?? '',
                            $ag->nome_cliente ?? '',
                            $ag->cidade ?? '',
                            $ag->regional ?? '',
                            $ag->data_agendamento ?? '',
                            $ag->tipo_servico ?? '',
                            $ag->periodo_sf ?? '',
                            $ag->data_disparo ?? '',
                            $ag->data_antecipacao ?? '',
                            $ag->identificador ?? '',
                            $ag->status ?? '',
                            $ag->fixado ?? '',
                            $ag->endereco_atendimento ?? '',
                            $ag->territorio_n ?? '',
                            $ag->status_retirada ?? '',
                            $ag->data_envio ?? '',

                            $vistoria->observacoes_gerais ?? '',
                            $vistoria->retorno_tecnico ?? '',
                            $vistoria->metros_drop !== null ? $vistoria->metros_drop . ' m' : '',

                            $resultadoVistoria,
                            $classificacao,
                            $statusBacklog,
                        ];

                        $checklistData = [];
                        foreach ($checklistKeys as $key) {
                            if (isset($itemsMap[$key])) {
                                $checklistData[] = $itemsMap[$key]->status ?? '';
                                $checklistData[] = $itemsMap[$key]->observacao ?? '';
                                $checklistData[] = $itemsMap[$key]->status_correcao ?? '';
                                $checklistData[] = $itemsMap[$key]->observacao_correcao ?? '';
                            } else {
                                $checklistData[] = '';
                                $checklistData[] = '';
                                $checklistData[] = '';
                                $checklistData[] = '';
                            }
                        }

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

        $vistorias = VistoriaSeguranca::with([
                'inspetor:id,nome',
                'regional:id,nome',
                'empresa:id,nome',
                'arquivos:id,vistoria_seguranca_id,path',
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
            'ID', 'Data', 'Inspetor', 'Regional', 'Cidade', 'T?cnico', 'CPF T?cnico', 'Empresa',
            'Supervisor', 'Placa', 'Despache', 'T?cnico no Local', 'Atividade Externa',
            'Motivo sem atividade externa',
            'Uso Capacete', 'Uso Cinto', 'Uso Talabarte', 'Uso Botas', 'Escada Est?vel',
            'Escada Amarrada', 'Cones Sinaliza??o', 'Escada Bom Estado', 'Observa??es',
            'Tipo V?lido',
        ];

        $callback = function () use ($vistorias, $columns) {
            $file = fopen('php://output', 'w');

            // BOM UTF-8 pro Excel
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($file, $columns);

            foreach ($vistorias as $vistoria) {
                $row = [
                    $vistoria->id,
                    optional($vistoria->created_at)->format('d/m/Y H:i:s') ?? '',
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
                    $vistoria->motivo_sem_atividade_externa ?? '',
                    $vistoria->uso_capacete ?? '',
                    $vistoria->uso_cinto ?? '',
                    $vistoria->uso_talabarte ?? '',
                    $vistoria->uso_botas ?? '',
                    $vistoria->escada_estavel ?? '',
                    $vistoria->escada_amarrada ?? '',
                    $vistoria->sinalizacao_cones ?? '',
                    $vistoria->escada_bom_estado ?? '',
                    $vistoria->observacoes ?? '',
                    $vistoria->tipo_valido ?? 'Yes',
                ];

                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * INVALIDAR LAUDO DE SEGURAN?A
     */
    public function invalidar(Request $request, VistoriaSeguranca $vistoria)
    {
        $vistoria->update(['tipo_valido' => 'No']);

        return response()->json([
            'message' => 'Laudo (Qualidade) invalidado com sucesso.',
            'data' => $vistoria
        ]);
    }
}
