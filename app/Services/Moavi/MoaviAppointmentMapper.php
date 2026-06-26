<?php

namespace App\Services\Moavi;

use DateTimeInterface;
use stdClass;

class MoaviAppointmentMapper
{
    public const SOURCE_COLUMNS = [
        'id',
        'numero_compromisso',
        'data_primeiro_agendamento',
        'inicio_janela_chegada',
        'termino_janela_chegada',
        'inicio_agendado',
        'termino_agendado',
        'inicio_servico',
        'termino_servico',
        'nome_tecnico',
        'empresa_tecnico',
        'cidade',
        'micro_territorio',
        'territorio_servico',
        'motivo_reagendamento',
        'fixado',
        'dt_abertura',
        'codigo_cliente',
        'numero_ordem_trabalho',
        'numero_caso',
        'ativo',
        'cpf_cnpj',
        'data_agendamento',
        'tipo_trabalho',
        'subtipo_trabalho',
        'status',
        'motivo_caso',
        'submotivo_caso',
        'codigo_baixa',
        'motivo_cancelamento',
        'motivo_suspensao',
        'olt',
        'cto',
        'data_ultima_modificacao',
        'foi_reagendado',
        'conveniencia_cliente',
        'solicita_antecipacao',
        'quantas_vezes',
        'tecnico_habilitado_indicar_movel',
        'cliente_aptos_para_chip',
        'criado_por',
        'pontos_mesh_instalar',
        'pontos_tv_instalar',
        'chip_entregar',
        'workorder_r_case',
        'indicacao_feita_pelo_tecnico',
        'prioridade',
        'nome_conta',
        'agendamento_fura_fila',
        'cpf',
        'string_ppoe_user',
        'distancia_termino_servico',
        'canal',
        'vendedor',
    ];

    public function columns(): array
    {
        return self::SOURCE_COLUMNS;
    }

    public function toApi(stdClass $row, ?string $companyCnpj = null): array
    {
        $payload = [
            'cnpj_empresa' => $companyCnpj ?: config('moavi.company_cnpj'),
        ];

        foreach (self::SOURCE_COLUMNS as $column) {
            $payload[$column] = $this->normalizeValue($row->{$column} ?? null);
        }

        return $payload;
    }

    public function rowHash(stdClass $row, ?string $companyCnpj = null): string
    {
        return hash('sha256', json_encode(
            $this->toApi($row, $companyCnpj),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function normalizeValue($value)
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
