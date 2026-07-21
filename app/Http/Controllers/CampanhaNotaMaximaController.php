<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class CampanhaNotaMaximaController extends Controller
{
    private const CAMPAIGN_NAME = 'CAMPANHA OPERAÇÃO NOTA MÁXIMA';

    public function ranking()
    {
        $ttlSeconds = max(60, (int) env('CAMPANHA_NOTA_MAXIMA_CACHE_SECONDS', 300));

        try {
            $payload = Cache::remember(
                'campanha-nota-maxima:ranking:v2',
                now()->addSeconds($ttlSeconds),
                fn () => $this->buildPayload()
            );

            return response()->json($payload);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Nao foi possivel atualizar a base da campanha.',
            ], 500);
        }
    }

    private function buildPayload(): array
    {
        $rows = collect(DB::connection($this->connectionName())->select($this->sql()))
            ->map(fn ($row) => $this->publicRow($row))
            ->values()
            ->all();

        $partialRow = collect($rows)->first(fn ($row) => !empty($row['parcial_ate']));
        $partialUntil = $partialRow['parcial_ate'] ?? null;

        return [
            'data' => $rows,
            'meta' => [
                'campaign' => self::CAMPAIGN_NAME,
                'partial_until' => $partialUntil,
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function sql(): string
    {
        $path = resource_path('sql/campanha_nota_maxima_ranking.sql');

        if (!is_file($path)) {
            throw new \RuntimeException("Arquivo SQL da campanha nao encontrado: {$path}");
        }

        return rtrim(file_get_contents($path), " \t\n\r\0\x0B;");
    }

    private function connectionName(): string
    {
        return env('CAMPANHA_NOTA_MAXIMA_DB_CONNECTION', config('database.default'));
    }

    private function publicRow(object $row): array
    {
        return [
            'employee_id' => $this->stringOrNull($row->employee_id ?? null),
            'usuario' => $this->stringOrNull($row->usuario ?? null),
            'nome_tecnico_origem' => $this->maskName($row->nome_tecnico_origem ?? null),
            'nome_tecnico_correto' => $this->maskName($row->nome_tecnico_correto ?? null),
            'cargo_usuario' => $this->stringOrNull($row->cargo_usuario ?? null),
            'territorio_usuario' => $this->stringOrNull($row->territorio_usuario ?? null),
            'regional_usuario' => $this->stringOrNull($row->regional_usuario ?? null),
            'empresa_usuario' => $this->stringOrNull($row->empresa_usuario ?? null),
            'parcial_ate' => $this->stringOrNull($row->parcial_ate ?? null),
            'dias_produzidos' => $this->intOrNull($row->dias_produzidos ?? null),
            'primeiro_dia_producao' => $this->stringOrNull($row->primeiro_dia_producao ?? null),
            'ultimo_dia_producao' => $this->stringOrNull($row->ultimo_dia_producao ?? null),
            'total_os_executadas' => $this->intOrNull($row->total_os_executadas ?? null),
            'qtd_reparo' => $this->intOrNull($row->qtd_reparo ?? null),
            'qtd_irr' => $this->intOrNull($row->qtd_irr ?? null),
            'irr_pct' => $this->floatOrNull($row->irr_pct ?? null),
            'pontuacao_total' => $this->floatOrNull($row->pontuacao_total ?? null),
            'produtividade' => $this->floatOrNull($row->produtividade ?? null),
            'score_produtividade' => $this->floatOrNull($row->score_produtividade ?? null),
            'score_irr' => $this->floatOrNull($row->score_irr ?? null),
            'score_final' => $this->floatOrNull($row->score_final ?? null),
            'mix_pct_principal' => $this->floatOrNull($row->mix_pct_principal ?? null),
            'mix_pct_alelo' => $this->floatOrNull($row->mix_pct_alelo ?? null),
            'apto_parcial_principal' => $this->stringOrNull($row->apto_parcial_principal ?? null),
            'apto_parcial_alelo' => $this->stringOrNull($row->apto_parcial_alelo ?? null),
            'premio_parcial' => $this->stringOrNull($row->premio_parcial ?? null),
        ];
    }

    private function maskName($value): ?string
    {
        $name = $this->stringOrNull($value);

        if ($name === null) {
            return null;
        }

        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);

        if (!$parts || count($parts) === 0) {
            return null;
        }

        $maskedParts = array_map(function (string $part, int $index): string {
            if ($index === 0) {
                return $part;
            }

            preg_match_all('/./u', $part, $characters);
            $length = count($characters[0] ?? []);

            return str_repeat('*', max(6, $length));
        }, $parts, array_keys($parts));

        return implode(' ', $maskedParts);
    }

    private function stringOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 3);
    }
}
