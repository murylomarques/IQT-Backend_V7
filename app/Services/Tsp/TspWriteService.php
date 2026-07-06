<?php

namespace App\Services\Tsp;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class TspWriteService
{
    public function __construct(
        private TspAppointmentService $appointments,
        private TspAppointmentMapper $mapper,
        private SalesforceClient $salesforce,
        private TspDemoService $demo
    ) {
    }

    public function fixar(string $id, bool $fixado): array
    {
        if ($this->demo->enabled()) {
            return $this->demo->fixar($id, $fixado);
        }

        $source = $this->appointments->eligibleAppointment($id);
        $sa = $this->serviceAppointment($source);

        $before = [
            'fixado' => (bool) ($sa['FSL__Pinned__c'] ?? false),
        ];

        $this->salesforce->patchSObject('ServiceAppointment', $sa['Id'], [
            'FSL__Pinned__c' => $fixado,
        ]);

        return [
            'operation' => 'fixar',
            'id' => $source->id,
            'numero_compromisso' => $source->numero_compromisso,
            'before' => $before,
            'after' => [
                'fixado' => $fixado,
            ],
        ];
    }

    public function mudarHorario(string $id, string $novoInicio, string $novoFim, ?bool $fixado): array
    {
        if ($this->demo->enabled()) {
            return $this->demo->mudarHorario($id, $novoInicio, $novoFim, $fixado);
        }

        $source = $this->appointments->eligibleAppointment($id);
        $sa = $this->serviceAppointment($source);
        [$start, $end] = $this->salesforceDateRange($novoInicio, $novoFim);

        $payload = [
            'SchedStartTime' => $start,
            'SchedEndTime' => $end,
        ];

        if ($fixado !== null) {
            $payload['FSL__Pinned__c'] = $fixado;
        }

        $this->salesforce->patchSObject('ServiceAppointment', $sa['Id'], $payload);

        return [
            'operation' => 'mudar_horario',
            'id' => $source->id,
            'numero_compromisso' => $source->numero_compromisso,
            'before' => [
                'inicio_agendado' => $sa['SchedStartTime'] ?? null,
                'termino_agendado' => $sa['SchedEndTime'] ?? null,
                'fixado' => (bool) ($sa['FSL__Pinned__c'] ?? false),
            ],
            'after' => [
                'inicio_agendado' => $novoInicio,
                'termino_agendado' => $novoFim,
                'fixado' => $fixado ?? (bool) ($sa['FSL__Pinned__c'] ?? false),
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
        if ($this->demo->enabled()) {
            return $this->demo->mudarTecnico($id, $novoTecnicoId, $novoInicio, $novoFim, $fixado);
        }

        $source = $this->appointments->eligibleAppointment($id);
        $sa = $this->serviceAppointment($source);
        $resource = $this->serviceResource($novoTecnicoId);
        $assigned = $this->assignedResource($sa['Id']);

        $saPayload = [];
        if ($novoInicio !== null || $novoFim !== null) {
            if ($novoInicio === null || $novoFim === null) {
                throw new RuntimeException('novo_inicio e novo_fim devem ser enviados juntos.');
            }

            [$start, $end] = $this->salesforceDateRange($novoInicio, $novoFim);
            $saPayload['SchedStartTime'] = $start;
            $saPayload['SchedEndTime'] = $end;
        }

        if ($fixado !== null) {
            $saPayload['FSL__Pinned__c'] = $fixado;
        }

        $requests = [];
        if ($assigned) {
            $requests[] = [
                'method' => 'PATCH',
                'url' => '/services/data/' . config('tsp.salesforce.api_version') . '/sobjects/AssignedResource/' . $assigned['Id'],
                'referenceId' => 'UpdateAssignedResource',
                'body' => [
                    'ServiceResourceId' => $resource['Id'],
                ],
            ];
        } else {
            $requests[] = [
                'method' => 'POST',
                'url' => '/services/data/' . config('tsp.salesforce.api_version') . '/sobjects/AssignedResource',
                'referenceId' => 'CreateAssignedResource',
                'body' => [
                    'ServiceAppointmentId' => $sa['Id'],
                    'ServiceResourceId' => $resource['Id'],
                ],
            ];
        }

        if ($saPayload) {
            $requests[] = [
                'method' => 'PATCH',
                'url' => '/services/data/' . config('tsp.salesforce.api_version') . '/sobjects/ServiceAppointment/' . $sa['Id'],
                'referenceId' => 'UpdateServiceAppointment',
                'body' => $saPayload,
            ];
        }

        $this->assertCompositeSuccess($this->salesforce->composite($requests));

        return [
            'operation' => 'mudar_tecnico',
            'id' => $source->id,
            'numero_compromisso' => $source->numero_compromisso,
            'before' => [
                'nome_tecnico' => $sa['TechnicianName__c'] ?? null,
                'service_resource_id' => $assigned['ServiceResourceId'] ?? null,
                'inicio_agendado' => $sa['SchedStartTime'] ?? null,
                'termino_agendado' => $sa['SchedEndTime'] ?? null,
                'fixado' => (bool) ($sa['FSL__Pinned__c'] ?? false),
            ],
            'after' => [
                'nome_tecnico' => $resource['Name'] ?? null,
                'service_resource_id' => $resource['Id'],
                'inicio_agendado' => $novoInicio ?? ($sa['SchedStartTime'] ?? null),
                'termino_agendado' => $novoFim ?? ($sa['SchedEndTime'] ?? null),
                'fixado' => $fixado ?? (bool) ($sa['FSL__Pinned__c'] ?? false),
            ],
        ];
    }

    public function suspender(string $id, string $descricao, UploadedFile $imagem): array
    {
        if ($this->demo->enabled()) {
            return $this->demo->suspender($id, $descricao, $imagem);
        }

        $source = $this->appointments->eligibleAppointment($id);
        $sa = $this->serviceAppointment($source);
        $caseId = $sa['WorkOrder__r']['CaseId'] ?? null;

        if (!$caseId) {
            throw new RuntimeException('Agendamento sem Case pai para criar nota.');
        }

        $html = $this->formattedNoteHtml($source, $descricao, $imagem);
        $requests = [
            [
                'method' => 'PATCH',
                'url' => '/services/data/' . config('tsp.salesforce.api_version') . '/sobjects/ServiceAppointment/' . $sa['Id'],
                'referenceId' => 'SuspendServiceAppointment',
                'body' => [
                    'Status' => 'Suspensa',
                ],
            ],
            [
                'method' => 'POST',
                'url' => '/services/data/' . config('tsp.salesforce.api_version') . '/sobjects/ContentNote',
                'referenceId' => 'SuspensionNote',
                'body' => [
                    'Title' => 'Suspensao TSP - ' . $source->numero_compromisso,
                    'Content' => base64_encode($html),
                ],
            ],
            [
                'method' => 'POST',
                'url' => '/services/data/' . config('tsp.salesforce.api_version') . '/sobjects/ContentDocumentLink',
                'referenceId' => 'SuspensionNoteLink',
                'body' => [
                    'LinkedEntityId' => $caseId,
                    'ContentDocumentId' => '@{SuspensionNote.id}',
                    'ShareType' => 'V',
                    'Visibility' => 'AllUsers',
                ],
            ],
        ];

        $response = $this->salesforce->composite($requests);
        $this->assertCompositeSuccess($response);
        $note = $this->compositePart($response, 'SuspensionNote');
        $link = $this->compositePart($response, 'SuspensionNoteLink');

        return [
            'operation' => 'suspender',
            'id' => $source->id,
            'numero_compromisso' => $source->numero_compromisso,
            'case_id' => $caseId,
            'before' => [
                'status' => $sa['Status'] ?? null,
            ],
            'after' => [
                'status' => 'Suspensa',
            ],
            'nota' => [
                'content_note_id' => $note['body']['id'] ?? null,
                'content_document_link_id' => $link['body']['id'] ?? null,
                'descricao' => $descricao,
                'imagem_nome' => $imagem->getClientOriginalName(),
            ],
        ];
    }

    public function logWrite(?int $clientId, string $requestId, string $operation, stdClass $source, string $status, array $requestPayload, array $responsePayload = [], ?string $errorCode = null): void
    {
        DB::connection(config('tsp.connection'))->table('write_operation_logs')->insert([
            'api_client_id' => $clientId,
            'request_id' => $requestId,
            'operation' => $operation,
            'source_id' => $source->id,
            'numero_compromisso' => $source->numero_compromisso ?? null,
            'salesforce_id' => $source->id,
            'status' => $status,
            'error_code' => $errorCode,
            'request_payload' => json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload' => $responsePayload ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
        ]);
    }

    private function serviceAppointment(stdClass $source): array
    {
        $id = $this->soqlEscape($source->id);
        $soql = "
            SELECT Id, AppointmentNumber, Status, TechniciansCompany__c, TechnicianName__c,
                   SchedStartTime, SchedEndTime, FSL__Pinned__c, WorkOrder__c,
                   WorkOrder__r.CaseId, WorkOrder__r.City
            FROM ServiceAppointment
            WHERE Id = '{$id}'
            LIMIT 1
        ";

        $result = $this->salesforce->query($soql);
        $records = $result['records'] ?? [];
        if (count($records) !== 1) {
            throw new RuntimeException('ServiceAppointment nao encontrado no Salesforce.');
        }

        $sa = $records[0];
        $this->assertSalesforceScope($sa);

        return $sa;
    }

    private function assignedResource(string $serviceAppointmentId): ?array
    {
        $id = $this->soqlEscape($serviceAppointmentId);
        $soql = "
            SELECT Id, ServiceAppointmentId, ServiceResourceId, ServiceResource.Name
            FROM AssignedResource
            WHERE ServiceAppointmentId = '{$id}'
        ";

        $records = $this->salesforce->query($soql)['records'] ?? [];
        if (count($records) > 1) {
            throw new RuntimeException('Agendamento possui multiplos recursos atribuidos; operacao bloqueada.');
        }

        return $records[0] ?? null;
    }

    private function serviceResource(string $id): array
    {
        $escaped = $this->soqlEscape($id);
        $soql = "
            SELECT Id, Name, IsActive, ResourceCompany__c
            FROM ServiceResource
            WHERE Id = '{$escaped}'
            LIMIT 1
        ";

        $records = $this->salesforce->query($soql)['records'] ?? [];
        if (count($records) !== 1) {
            throw new RuntimeException('Tecnico nao encontrado no Salesforce.');
        }

        $resource = $records[0];
        if (!($resource['IsActive'] ?? false)) {
            throw new RuntimeException('Tecnico inativo no Salesforce.');
        }

        if ($this->normalize($resource['ResourceCompany__c'] ?? '') !== $this->normalize(config('tsp.source_filters.empresa_tecnico'))) {
            throw new RuntimeException('Tecnico nao pertence a TSP.');
        }

        return $resource;
    }

    private function assertSalesforceScope(array $sa): void
    {
        if ($this->normalize($sa['TechniciansCompany__c'] ?? '') !== $this->normalize(config('tsp.source_filters.empresa_tecnico'))) {
            throw new RuntimeException('ServiceAppointment no Salesforce nao pertence a TSP.');
        }

        if ($this->normalize($sa['WorkOrder__r']['City'] ?? '') !== $this->normalize(config('tsp.source_filters.cidade'))) {
            throw new RuntimeException('ServiceAppointment no Salesforce nao pertence a cidade permitida.');
        }
    }

    private function salesforceDateRange(string $novoInicio, string $novoFim): array
    {
        $timezone = (string) config('tsp.timezone', 'America/Sao_Paulo');
        $start = Carbon::parse($novoInicio, $timezone);
        $end = Carbon::parse($novoFim, $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            throw new RuntimeException('novo_fim deve ser maior que novo_inicio.');
        }

        return [$start->toIso8601String(), $end->toIso8601String()];
    }

    private function formattedNoteHtml(stdClass $source, string $descricao, UploadedFile $imagem): string
    {
        $mime = $imagem->getMimeType() ?: 'image/jpeg';
        $imageData = base64_encode(file_get_contents($imagem->getRealPath()));
        $fileName = htmlspecialchars($imagem->getClientOriginalName(), ENT_QUOTES, 'UTF-8');
        $description = nl2br(htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8'));
        $numero = htmlspecialchars((string) $source->numero_compromisso, ENT_QUOTES, 'UTF-8');
        $tecnico = htmlspecialchars((string) $source->nome_tecnico, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div style="font-family: Arial, sans-serif; line-height: 1.45; color: #1f2933;">
  <h2 style="margin: 0 0 12px 0; font-size: 18px;">Suspensao TSP</h2>
  <p style="margin: 0 0 8px 0;"><strong>Compromisso:</strong> {$numero}</p>
  <p style="margin: 0 0 14px 0;"><strong>Tecnico:</strong> {$tecnico}</p>
  <div style="margin: 0 0 14px 0;">
    <strong>Descricao:</strong><br />
    {$description}
  </div>
  <figure style="margin: 0; padding: 12px; border: 1px solid #d8dde6; border-radius: 6px; background: #f8fafc; display: inline-block;">
    <img src="data:{$mime};base64,{$imageData}" alt="{$fileName}" style="display: block; width: 520px; max-width: 100%; height: auto;" />
    <figcaption style="margin-top: 8px; font-size: 12px; color: #5f6b7a;">Imagem: {$fileName}</figcaption>
  </figure>
</div>
HTML;
    }

    private function assertCompositeSuccess(array $response): void
    {
        foreach (($response['compositeResponse'] ?? []) as $part) {
            $status = (int) ($part['httpStatusCode'] ?? 0);
            if ($status < 200 || $status > 299) {
                throw new RuntimeException('Falha Salesforce composite: ' . json_encode($part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }

    private function compositePart(array $response, string $referenceId): array
    {
        foreach (($response['compositeResponse'] ?? []) as $part) {
            if (($part['referenceId'] ?? null) === $referenceId) {
                return $part;
            }
        }

        return [];
    }

    private function normalize(?string $value): string
    {
        return $this->mapper->normalizeText($value);
    }

    private function soqlEscape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
