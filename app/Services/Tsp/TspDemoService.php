<?php

namespace App\Services\Tsp;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use stdClass;

class TspDemoService
{
    public function __construct(private TspAppointmentMapper $mapper)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('tsp.demo_mode', false);
    }

    public function next15Days(int $pageSize, ?string $pageToken = null): array
    {
        $today = Carbon::now($this->timezone())->startOfDay();
        $end = $today->copy()->addDays(15);
        $rows = $this->rows();
        $offset = $this->decodeCursor($pageToken);
        $pageRows = array_slice($rows, $offset, $pageSize);
        $nextOffset = $offset + count($pageRows);
        $hasMore = $nextOffset < count($rows);

        return [
            'demo' => true,
            'data_source' => 'demo',
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
                'next_page_token' => $hasMore ? $this->encodeCursor($nextOffset) : null,
            ],
            'items' => array_map(fn (stdClass $row): array => $this->mapper->toApi($row), $pageRows),
        ];
    }

    public function eligibleAppointment(string $id): stdClass
    {
        foreach ($this->rows() as $row) {
            if (strcasecmp($row->id, $id) === 0 || strcasecmp($row->numero_compromisso, $id) === 0) {
                return $row;
            }
        }

        throw new RuntimeException('Agendamento demo nao encontrado. Consulte /agendamentos/proximos-15-dias e use um id ou numero_compromisso retornado.');
    }

    public function fixar(string $id, bool $fixado): array
    {
        $source = $this->eligibleAppointment($id);

        return [
            'demo' => true,
            'data_source' => 'demo',
            'operation' => 'fixar',
            'id' => $source->id,
            'numero_compromisso' => $source->numero_compromisso,
            'before' => [
                'fixado' => (bool) $source->fixado,
            ],
            'after' => [
                'fixado' => $fixado,
            ],
        ];
    }

    public function mudarHorario(string $id, string $novoInicio, string $novoFim, ?bool $fixado): array
    {
        $source = $this->eligibleAppointment($id);
        $this->assertDateRange($novoInicio, $novoFim);

        return [
            'demo' => true,
            'data_source' => 'demo',
            'operation' => 'mudar_horario',
            'id' => $source->id,
            'numero_compromisso' => $source->numero_compromisso,
            'before' => [
                'inicio_agendado' => $source->inicio_agendado,
                'termino_agendado' => $source->termino_agendado,
                'fixado' => (bool) $source->fixado,
            ],
            'after' => [
                'inicio_agendado' => $novoInicio,
                'termino_agendado' => $novoFim,
                'fixado' => $fixado ?? (bool) $source->fixado,
            ],
        ];
    }

    public function mudarTecnico(
        string $id,
        string $novoTecnicoId,
        ?string $novoInicio,
        ?string $novoFim,
        ?bool $fixado
    ): array {
        $source = $this->eligibleAppointment($id);

        if ($novoInicio !== null || $novoFim !== null) {
            if ($novoInicio === null || $novoFim === null) {
                throw new RuntimeException('novo_inicio e novo_fim devem ser enviados juntos.');
            }

            $this->assertDateRange($novoInicio, $novoFim);
        }

        return [
            'demo' => true,
            'data_source' => 'demo',
            'operation' => 'mudar_tecnico',
            'id' => $source->id,
            'numero_compromisso' => $source->numero_compromisso,
            'before' => [
                'nome_tecnico' => $source->nome_tecnico,
                'service_resource_id' => $this->currentResourceId($source),
                'inicio_agendado' => $source->inicio_agendado,
                'termino_agendado' => $source->termino_agendado,
                'fixado' => (bool) $source->fixado,
            ],
            'after' => [
                'nome_tecnico' => $this->technicianName($novoTecnicoId),
                'service_resource_id' => $novoTecnicoId,
                'inicio_agendado' => $novoInicio ?? $source->inicio_agendado,
                'termino_agendado' => $novoFim ?? $source->termino_agendado,
                'fixado' => $fixado ?? (bool) $source->fixado,
            ],
        ];
    }

    public function suspender(string $id, string $descricao, UploadedFile $imagem): array
    {
        $source = $this->eligibleAppointment($id);

        return [
            'demo' => true,
            'data_source' => 'demo',
            'operation' => 'suspender',
            'id' => $source->id,
            'numero_compromisso' => $source->numero_compromisso,
            'case_id' => '500DEMO00000001AAA',
            'before' => [
                'status' => $source->status,
            ],
            'after' => [
                'status' => 'Suspensa',
            ],
            'nota' => [
                'content_note_id' => '069DEMO00000001AAA',
                'content_document_link_id' => '06ADEMO00000001AAA',
                'descricao' => $descricao,
                'imagem_nome' => $imagem->getClientOriginalName(),
            ],
        ];
    }

    /**
     * @return stdClass[]
     */
    private function rows(): array
    {
        $today = Carbon::now($this->timezone())->startOfDay();
        $now = Carbon::now($this->timezone());
        $empresa = (string) config('tsp.source_filters.empresa_tecnico');
        $cidade = (string) config('tsp.source_filters.cidade');

        $templates = [
            ['ANA PAULA TSP DEMO', 0, '08:00', '10:00', 'Instalacao', 'Internet Fibra', false, 'Alta'],
            ['BRUNO MARTINS TSP DEMO', 1, '09:00', '11:00', 'Manutencao', 'Sem sinal', true, 'Normal'],
            ['CARLOS EDUARDO TSP DEMO', 2, '10:00', '12:00', 'Reparo', 'Lentidao', false, 'Normal'],
            ['DANIELA COSTA TSP DEMO', 3, '13:00', '15:00', 'Instalacao', 'TV', false, 'Baixa'],
            ['EDUARDO LIMA TSP DEMO', 4, '14:00', '16:00', 'Mudanca de endereco', 'Residencial', true, 'Alta'],
            ['FERNANDA ALVES TSP DEMO', 5, '08:30', '10:30', 'Manutencao', 'Cliente ausente', false, 'Normal'],
            ['GUSTAVO ROCHA TSP DEMO', 7, '11:00', '13:00', 'Reparo', 'Equipamento', false, 'Alta'],
            ['HELENA SANTOS TSP DEMO', 9, '15:00', '17:00', 'Instalacao', 'Internet Fibra', true, 'Normal'],
            ['IGOR NUNES TSP DEMO', 11, '08:00', '10:00', 'Manutencao', 'Sinal intermitente', false, 'Normal'],
            ['JULIANA FERREIRA TSP DEMO', 13, '13:30', '15:30', 'Reparo', 'Troca de conector', false, 'Baixa'],
            ['LEONARDO MELO TSP DEMO', 14, '09:30', '11:30', 'Instalacao', 'Combo', true, 'Alta'],
            ['MARIANA RIBEIRO TSP DEMO', 15, '16:00', '18:00', 'Manutencao', 'Revisita', false, 'Normal'],
        ];

        $rows = [];
        foreach ($templates as $index => $template) {
            [$tecnico, $dayOffset, $startHour, $endHour, $tipo, $subtipo, $fixado, $prioridade] = $template;
            $number = $index + 1;
            $windowStart = $this->atTime($today->copy()->addDays($dayOffset), $startHour);
            $windowEnd = $this->atTime($today->copy()->addDays($dayOffset), $endHour);
            $scheduledStart = $windowStart->copy()->addMinutes(20);
            $scheduledEnd = $scheduledStart->copy()->addMinutes(75);

            $rows[] = (object) [
                'id' => sprintf('08pDEMO%08dAAA', $number),
                'numero_compromisso' => sprintf('SA-DEMO-%04d', $number),
                'data_agendamento' => $this->format($windowStart),
                'data_ultima_modificacao' => $this->format($now->copy()->subHours($number)),
                'nome_tecnico' => $tecnico,
                'empresa_tecnico' => $empresa,
                'cidade' => $cidade,
                'micro_territorio' => 'BASE SOROCABA DEMO',
                'territorio_servico' => sprintf('SOR%03d', $number),
                'inicio_janela_chegada' => $this->format($windowStart),
                'termino_janela_chegada' => $this->format($windowEnd),
                'inicio_agendado' => $this->format($scheduledStart),
                'termino_agendado' => $this->format($scheduledEnd),
                'tipo_trabalho' => $tipo,
                'subtipo_trabalho' => $subtipo,
                'status' => 'agendado',
                'fixado' => $fixado,
                'numero_ordem_trabalho' => sprintf('WO-DEMO-%04d', $number),
                'numero_caso' => sprintf('CASE-DEMO-%04d', $number),
                'nome_conta' => sprintf('Cliente Demo %02d', $number),
                'prioridade' => $prioridade,
            ];
        }

        return $rows;
    }

    private function atTime(Carbon $date, string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->setTime($hour, $minute);
    }

    private function assertDateRange(string $novoInicio, string $novoFim): void
    {
        $start = Carbon::parse($novoInicio, $this->timezone());
        $end = Carbon::parse($novoFim, $this->timezone());

        if ($end->lessThanOrEqualTo($start)) {
            throw new RuntimeException('novo_fim deve ser maior que novo_inicio.');
        }
    }

    private function currentResourceId(stdClass $source): string
    {
        $number = (int) substr((string) $source->numero_compromisso, -4);

        return sprintf('0HnDEMO%08dAAA', max($number, 1));
    }

    private function technicianName(string $resourceId): string
    {
        $known = [
            '0HnDEMO00000001AAA' => 'ANA PAULA TSP DEMO',
            '0HnDEMO00000002AAA' => 'BRUNO MARTINS TSP DEMO',
            '0HnDEMO00000003AAA' => 'CARLOS EDUARDO TSP DEMO',
            '0HnDEMO00000004AAA' => 'DANIELA COSTA TSP DEMO',
        ];

        return $known[$resourceId] ?? 'TECNICO TSP DEMO';
    }

    private function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'kind' => 'tsp_demo_next15',
            'offset' => $offset,
        ], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $token): int
    {
        if (!$token) {
            return 0;
        }

        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $payload = $json ? json_decode($json, true) : null;

        if (!is_array($payload) || ($payload['kind'] ?? null) !== 'tsp_demo_next15') {
            throw new RuntimeException('page_token demo invalido.');
        }

        return max((int) ($payload['offset'] ?? 0), 0);
    }

    private function format(Carbon $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    private function timezone(): string
    {
        return (string) config('tsp.timezone', 'America/Sao_Paulo');
    }
}
