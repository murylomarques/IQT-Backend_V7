<?php

namespace App\Services\Tsp;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class TspAppointmentService
{
    public function __construct(
        private TspAppointmentMapper $mapper,
        private TspDemoService $demo
    )
    {
    }

    public function next15Days(int $pageSize, ?string $pageToken = null): array
    {
        if ($this->demo->enabled()) {
            return $this->demo->next15Days($pageSize, $pageToken);
        }

        $today = Carbon::now($this->timezone())->startOfDay();
        $end = $today->copy()->addDays(15);
        $cursor = $this->decodeCursor($pageToken);

        $query = $this->periodQuery($today, $end)
            ->orderBy('data_agendamento')
            ->orderBy('id')
            ->limit($pageSize + 1);

        if ($cursor) {
            if (($cursor['kind'] ?? null) !== 'tsp_next15') {
                throw new RuntimeException('page_token invalido.');
            }

            $lastDate = $cursor['last_data_agendamento'] ?? null;
            $lastId = $cursor['last_id'] ?? null;
            if ($lastDate && $lastId) {
                $query->where(function (Builder $builder) use ($lastDate, $lastId): void {
                    $builder->where('data_agendamento', '>', $lastDate)
                        ->orWhere(function (Builder $inner) use ($lastDate, $lastId): void {
                            $inner->where('data_agendamento', '=', $lastDate)
                                ->where('id', '>', $lastId);
                        });
                });
            }
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $pageSize;
        $pageRows = $rows->take($pageSize)->values();
        $lastRow = $pageRows->last();

        return [
            'periodo' => [
                'inicio' => $today->toDateString(),
                'fim' => $end->toDateString(),
            ],
            'filters' => [
                'empresa_tecnico' => config('tsp.source_filters.empresa_tecnico'),
                'cidade' => config('tsp.source_filters.cidade'),
            ],
            'pagination' => [
                'page_size' => $pageSize,
                'has_more' => $hasMore,
                'next_page_token' => $hasMore && $lastRow ? $this->encodeCursor([
                    'kind' => 'tsp_next15',
                    'last_data_agendamento' => $lastRow->data_agendamento,
                    'last_id' => $lastRow->id,
                ]) : null,
            ],
            'items' => $pageRows->map(fn (stdClass $row): array => $this->mapper->toApi($row))->all(),
        ];
    }

    public function eligibleAppointment(string $id): stdClass
    {
        if ($this->demo->enabled()) {
            return $this->demo->eligibleAppointment($id);
        }

        $query = DB::connection(config('tsp.source_connection'))
            ->table(config('tsp.source_table'))
            ->select($this->mapper->columns())
            ->where(function (Builder $builder) use ($id): void {
                $builder->where('id', $id)->orWhere('numero_compromisso', $id);
            })
            ->whereRaw(
                "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(empresa_tecnico, ''), ' ', ''), '_', ''), '-', '')) = ?",
                [$this->normalizedFilter('empresa_tecnico')]
            )
            ->whereRaw(
                "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(cidade, ''), ' ', ''), '_', ''), '-', '')) = ?",
                [$this->normalizedFilter('cidade')]
            );

        $row = $query->first();

        if (!$row) {
            throw new RuntimeException('Agendamento nao encontrado ou fora da regra TSP/Sorocaba.');
        }

        return $row;
    }

    private function periodQuery(Carbon $start, Carbon $end): Builder
    {
        $from = $start->copy()->startOfDay()->format('Y-m-d H:i:s');
        $to = $end->copy()->addDay()->startOfDay()->format('Y-m-d H:i:s');

        return DB::connection(config('tsp.source_connection'))
            ->table(config('tsp.source_table'))
            ->select($this->mapper->columns())
            ->whereNotNull('data_agendamento')
            ->where('data_agendamento', '>=', $from)
            ->where('data_agendamento', '<', $to)
            ->whereRaw(
                "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(empresa_tecnico, ''), ' ', ''), '_', ''), '-', '')) = ?",
                [$this->normalizedFilter('empresa_tecnico')]
            )
            ->whereRaw(
                "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(cidade, ''), ' ', ''), '_', ''), '-', '')) = ?",
                [$this->normalizedFilter('cidade')]
            );
    }

    private function normalizedFilter(string $key): string
    {
        return $this->mapper->normalizeText((string) config("tsp.source_filters.{$key}"));
    }

    private function timezone(): string
    {
        return (string) config('tsp.timezone', 'America/Sao_Paulo');
    }

    private function encodeCursor(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $token): ?array
    {
        if (!$token) {
            return null;
        }

        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $payload = $json ? json_decode($json, true) : null;

        if (!is_array($payload)) {
            throw new RuntimeException('page_token invalido.');
        }

        return $payload;
    }
}
