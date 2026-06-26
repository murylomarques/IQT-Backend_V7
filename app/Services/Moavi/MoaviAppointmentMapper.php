<?php

namespace App\Services\Moavi;

use DateTimeInterface;
use stdClass;

class MoaviAppointmentMapper
{
    public const API_COLUMNS = [
        'id',
        'numero_compromisso',
        'dt_abertura',
        'data_primeiro_agendamento',
        'tipo_trabalho',
        'termino_servico',
        'status',
    ];

    public const INTERNAL_COLUMNS = [
        'data_agendamento',
        'data_ultima_modificacao',
    ];

    public function columns(): array
    {
        return array_values(array_unique(array_merge(self::API_COLUMNS, self::INTERNAL_COLUMNS)));
    }

    public function toApi(stdClass $row, ?string $companyCnpj = null): array
    {
        $payload = [
            'cnpj_empresa' => $companyCnpj ?: config('moavi.company_cnpj'),
        ];

        foreach (self::API_COLUMNS as $column) {
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
