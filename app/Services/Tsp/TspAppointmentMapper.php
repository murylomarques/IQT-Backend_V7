<?php

namespace App\Services\Tsp;

use DateTimeInterface;
use stdClass;

class TspAppointmentMapper
{
    public const API_COLUMNS = [
        'id',
        'numero_compromisso',
        'data_agendamento',
        'data_ultima_modificacao',
        'nome_tecnico',
        'empresa_tecnico',
        'cidade',
        'micro_territorio',
        'territorio_servico',
        'inicio_janela_chegada',
        'termino_janela_chegada',
        'inicio_agendado',
        'termino_agendado',
        'tipo_trabalho',
        'subtipo_trabalho',
        'status',
        'fixado',
        'numero_ordem_trabalho',
        'numero_caso',
        'nome_conta',
        'prioridade',
    ];

    public function columns(): array
    {
        return self::API_COLUMNS;
    }

    public function toApi(stdClass $row): array
    {
        $payload = [
            'empresa_api' => 'TSP',
        ];

        if ((string) config('tsp.company_cnpj') !== '') {
            $payload['cnpj_empresa'] = config('tsp.company_cnpj');
        }

        foreach (self::API_COLUMNS as $column) {
            $payload[$column] = $this->normalizeValue($row->{$column} ?? null);
        }

        $payload['fixado'] = (bool) ($payload['fixado'] ?? false);
        $payload['status'] = $this->normalizeStatus($payload['status'] ?? null);

        return $payload;
    }

    private function normalizeValue($value)
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function normalizeStatus(?string $status): ?string
    {
        $normalized = $this->normalizeText($status);

        if (in_array($normalized, ['concluida', 'concluido'], true)) {
            return 'concluido';
        }

        if (in_array($normalized, ['cancelado', 'cancelada', 'canceled'], true)) {
            return 'cancelado';
        }

        if (in_array($normalized, ['suspensa', 'suspenso'], true)) {
            return 'suspenso';
        }

        if (in_array($normalized, [
            'agendado',
            'despachado',
            'despachada',
            'emdeslocamento',
            'chegadanolocal',
            'emexecucao',
            'naoiniciado',
            'naoiniciada',
            'emespera',
            'emexecuÃ§Ã£o',
        ], true)) {
            return 'agendado';
        }

        return $status;
    }

    public function normalizeText(?string $value): string
    {
        $text = strtolower(trim((string) $value));
        $text = strtr($text, [
            'Ã¡' => 'a',
            'Ã ' => 'a',
            'Ã£' => 'a',
            'Ã¢' => 'a',
            'Ã©' => 'e',
            'Ãª' => 'e',
            'Ã­' => 'i',
            'Ã³' => 'o',
            'Ãµ' => 'o',
            'Ã´' => 'o',
            'Ãº' => 'u',
            'Ã§' => 'c',
        ]);

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && $ascii !== '') {
            $text = strtolower($ascii);
        }

        return preg_replace('/[\s_-]+/', '', $text) ?? '';
    }
}
