<?php

namespace App\Services\Moavi;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class MoaviSyncService
{
    public function __construct(
        private MoaviAppointmentMapper $mapper,
        private MoaviCursor $cursor
    ) {
    }

    public function listAppointments(
        stdClass $client,
        Carbon $start,
        Carbon $end,
        string $endpoint,
        int $pageSize,
        ?string $pageToken = null
    ): array {
        $cursorPayload = $this->cursor->decode($pageToken);
        $syncKey = null;
        $session = null;

        if ($cursorPayload) {
            $this->assertCursor($cursorPayload, 'list', $client, $endpoint, $start, $end);
            $syncKey = $cursorPayload['sync_key'];
            $session = $this->activeSession((int) $cursorPayload['sync_session_id'], $client);
        } else {
            [$session, $syncKey] = $this->createSession($client, $endpoint, $start, $end);
        }

        $query = $this->periodQuery($start, $end)
            ->orderBy('data_agendamento')
            ->orderBy('id')
            ->limit($pageSize + 1);

        if ($cursorPayload && isset($cursorPayload['last_data_agendamento'], $cursorPayload['last_id'])) {
            $lastDate = $cursorPayload['last_data_agendamento'];
            $lastId = $cursorPayload['last_id'];
            $query->where(function (Builder $builder) use ($lastDate, $lastId): void {
                $builder->where('data_agendamento', '>', $lastDate)
                    ->orWhere(function (Builder $inner) use ($lastDate, $lastId): void {
                        $inner->where('data_agendamento', '=', $lastDate)
                            ->where('id', '>', $lastId);
                    });
            });
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $pageSize;
        $pageRows = $rows->take($pageSize)->values();
        $lastRow = $pageRows->last();

        return [
            'sync_key' => $syncKey,
            'periodo' => [
                'inicio' => $start->toDateString(),
                'fim' => $end->toDateString(),
            ],
            'snapshot' => [
                'id' => $session->id,
                'source_count' => (int) $session->source_count,
                'created_at' => $session->baseline_started_at,
                'expires_at' => $session->expires_at,
            ],
            'pagination' => [
                'page_size' => $pageSize,
                'has_more' => $hasMore,
                'next_page_token' => $hasMore && $lastRow
                    ? $this->cursor->encode([
                        'kind' => 'list',
                        'sync_session_id' => $session->id,
                        'sync_key' => $syncKey,
                        'client_id' => $client->id,
                        'endpoint' => $endpoint,
                        'period_start' => $start->toDateString(),
                        'period_end' => $end->toDateString(),
                        'last_data_agendamento' => $lastRow->data_agendamento,
                        'last_id' => $lastRow->id,
                    ])
                    : null,
            ],
            'items' => $pageRows
                ->map(fn (stdClass $row): array => $this->mapper->toApi($row, $client->cnpj_empresa ?? null))
                ->all(),
        ];
    }

    public function changes(stdClass $client, string $syncKey, int $pageSize, ?string $pageToken = null): array
    {
        $syncKeyHash = hash('sha256', $syncKey);
        $session = $this->sessionByKey($syncKeyHash, $client);
        $start = Carbon::parse($session->period_start, $this->timezone());
        $end = Carbon::parse($session->period_end, $this->timezone());

        $cursorPayload = $this->cursor->decode($pageToken);
        if ($cursorPayload) {
            $this->assertChangeCursor($cursorPayload, $client, $syncKeyHash);
        }

        $phase = $cursorPayload['phase'] ?? 'upserts';
        $changes = [];
        $hasMore = false;
        $nextPayload = null;

        if ($phase === 'upserts') {
            [$changes, $hasMore, $nextPayload] = $this->collectUpsertChanges(
                $client,
                $session,
                $start,
                $end,
                $pageSize,
                $cursorPayload
            );

            if (!$hasMore && count($changes) < $pageSize) {
                [$removedChanges, $removedHasMore, $removedNextPayload] = $this->collectRemovedChanges(
                    $session,
                    $start,
                    $end,
                    $pageSize - count($changes),
                    null
                );

                $changes = array_merge($changes, $removedChanges);
                $hasMore = $removedHasMore;
                $nextPayload = $removedNextPayload;
            }
        } else {
            [$changes, $hasMore, $nextPayload] = $this->collectRemovedChanges(
                $session,
                $start,
                $end,
                $pageSize,
                $cursorPayload
            );
        }

        $nextSyncKey = null;
        if (!$hasMore) {
            [, $nextSyncKey] = $this->createSession($client, 'alteracoes', $start, $end);
        }

        DB::connection(config('moavi.connection'))->table('sync_sessions')
            ->where('id', $session->id)
            ->update([
                'last_used_at' => $this->now(),
                'updated_at' => now(),
            ]);

        return [
            'sync_key' => $syncKey,
            'next_sync_key' => $nextSyncKey,
            'periodo' => [
                'inicio' => $start->toDateString(),
                'fim' => $end->toDateString(),
            ],
            'pagination' => [
                'page_size' => $pageSize,
                'has_more' => $hasMore,
                'next_page_token' => $hasMore && $nextPayload
                    ? $this->cursor->encode(array_merge($nextPayload, [
                        'kind' => 'changes',
                        'client_id' => $client->id,
                        'sync_key_hash' => $syncKeyHash,
                    ]))
                    : null,
            ],
            'changes' => $changes,
        ];
    }

    private function collectUpsertChanges(
        stdClass $client,
        stdClass $session,
        Carbon $start,
        Carbon $end,
        int $pageSize,
        ?array $cursorPayload
    ): array {
        $baselineHashes = $this->baselineHashes((int) $session->id);
        $query = $this->periodQuery($start, $end)
            ->where('data_ultima_modificacao', '>=', $session->baseline_started_at)
            ->orderBy('data_ultima_modificacao')
            ->orderBy('id');

        if ($cursorPayload && isset($cursorPayload['last_updated_at'], $cursorPayload['last_id'])) {
            $lastUpdatedAt = $cursorPayload['last_updated_at'];
            $lastId = $cursorPayload['last_id'];
            $query->where(function (Builder $builder) use ($lastUpdatedAt, $lastId): void {
                $builder->where('data_ultima_modificacao', '>', $lastUpdatedAt)
                    ->orWhere(function (Builder $inner) use ($lastUpdatedAt, $lastId): void {
                        $inner->where('data_ultima_modificacao', '=', $lastUpdatedAt)
                            ->where('id', '>', $lastId);
                    });
            });
        }

        $changes = [];
        $hasMore = false;
        $nextPayload = null;

        foreach ($query->cursor() as $row) {
            $rowHash = $this->mapper->rowHash($row, $client->cnpj_empresa ?? null);
            $previousHash = $baselineHashes[$row->id] ?? null;

            if ($previousHash === $rowHash) {
                continue;
            }

            $operation = $previousHash === null ? 'created' : 'updated';
            $change = [
                'operation' => $operation,
                'changed_at' => $row->data_ultima_modificacao,
                'id' => $row->id,
                'numero_compromisso' => $row->numero_compromisso,
                'item' => $this->mapper->toApi($row, $client->cnpj_empresa ?? null),
            ];

            if (count($changes) >= $pageSize) {
                $hasMore = true;
                $nextPayload = [
                    'phase' => 'upserts',
                    'last_updated_at' => $changes[$pageSize - 1]['changed_at'],
                    'last_id' => $changes[$pageSize - 1]['id'],
                ];
                break;
            }

            $changes[] = $change;
        }

        return [$changes, $hasMore, $nextPayload];
    }

    private function collectRemovedChanges(
        stdClass $session,
        Carbon $start,
        Carbon $end,
        int $pageSize,
        ?array $cursorPayload
    ): array {
        if ($pageSize <= 0) {
            return [[], true, [
                'phase' => 'removed',
                'last_source_id' => $cursorPayload['last_source_id'] ?? '',
            ]];
        }

        $currentIds = $this->currentIds($start, $end);
        $query = DB::connection(config('moavi.connection'))
            ->table('sync_session_items')
            ->where('sync_session_id', $session->id)
            ->orderBy('source_id');

        if ($cursorPayload && isset($cursorPayload['last_source_id'])) {
            $query->where('source_id', '>', $cursorPayload['last_source_id']);
        }

        $changes = [];
        $hasMore = false;
        $nextPayload = null;

        foreach ($query->cursor() as $item) {
            if (isset($currentIds[$item->source_id])) {
                continue;
            }

            $change = [
                'operation' => 'removed',
                'changed_at' => $this->now(),
                'id' => $item->source_id,
                'numero_compromisso' => $item->source_numero_compromisso,
                'previous' => [
                    'row_hash' => $item->row_hash,
                    'data_agendamento' => $item->source_schedule_at,
                    'data_ultima_modificacao' => $item->source_updated_at,
                ],
            ];

            if (count($changes) >= $pageSize) {
                $hasMore = true;
                $nextPayload = [
                    'phase' => 'removed',
                    'last_source_id' => $changes[$pageSize - 1]['id'],
                ];
                break;
            }

            $changes[] = $change;
        }

        return [$changes, $hasMore, $nextPayload];
    }

    private function createSession(stdClass $client, string $endpoint, Carbon $start, Carbon $end): array
    {
        $syncKey = 'msk_' . bin2hex(random_bytes(24));
        $baselineStartedAt = $this->now();
        $expiresAt = Carbon::parse($baselineStartedAt, $this->timezone())
            ->addDays((int) config('moavi.sync_key_ttl_days'))
            ->format('Y-m-d H:i:s');

        $sessionId = DB::connection(config('moavi.connection'))->table('sync_sessions')->insertGetId([
            'api_client_id' => $client->id,
            'sync_key_hash' => hash('sha256', $syncKey),
            'endpoint' => $endpoint,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'baseline_started_at' => $baselineStartedAt,
            'expires_at' => $expiresAt,
            'status' => 'active',
            'metadata' => json_encode([
                'source_connection' => config('moavi.source_connection'),
                'source_table' => config('moavi.source_table'),
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sourceCount = $this->snapshotSession($sessionId, $client, $start, $end);

        DB::connection(config('moavi.connection'))->table('sync_sessions')
            ->where('id', $sessionId)
            ->update([
                'baseline_completed_at' => $this->now(),
                'source_count' => $sourceCount,
                'updated_at' => now(),
            ]);

        return [$this->activeSession($sessionId, $client), $syncKey];
    }

    private function snapshotSession(int $sessionId, stdClass $client, Carbon $start, Carbon $end): int
    {
        $count = 0;
        $chunk = [];
        $chunkSize = (int) config('moavi.snapshot_insert_chunk', 1000);
        $moavi = DB::connection(config('moavi.connection'));

        foreach ($this->periodQuery($start, $end)->orderBy('data_agendamento')->orderBy('id')->cursor() as $row) {
            $chunk[] = [
                'sync_session_id' => $sessionId,
                'source_id' => $row->id,
                'source_numero_compromisso' => $row->numero_compromisso,
                'row_hash' => $this->mapper->rowHash($row, $client->cnpj_empresa ?? null),
                'source_updated_at' => $row->data_ultima_modificacao,
                'source_schedule_at' => $row->data_agendamento,
                'created_at' => now(),
            ];

            $count++;

            if (count($chunk) >= $chunkSize) {
                $moavi->table('sync_session_items')->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk) {
            $moavi->table('sync_session_items')->insert($chunk);
        }

        return $count;
    }

    private function baselineHashes(int $sessionId): array
    {
        return DB::connection(config('moavi.connection'))
            ->table('sync_session_items')
            ->where('sync_session_id', $sessionId)
            ->pluck('row_hash', 'source_id')
            ->all();
    }

    private function currentIds(Carbon $start, Carbon $end): array
    {
        $ids = [];
        foreach ($this->periodQuery($start, $end)->select('id')->cursor() as $row) {
            $ids[$row->id] = true;
        }

        return $ids;
    }

    private function periodQuery(Carbon $start, Carbon $end): Builder
    {
        $from = $start->copy()->startOfDay()->format('Y-m-d H:i:s');
        $to = $end->copy()->addDay()->startOfDay()->format('Y-m-d H:i:s');

        return DB::connection(config('moavi.source_connection'))
            ->table(config('moavi.source_table'))
            ->select($this->mapper->columns())
            ->whereNotNull('data_agendamento')
            ->where('data_agendamento', '>=', $from)
            ->where('data_agendamento', '<', $to);
    }

    private function activeSession(int $sessionId, stdClass $client): stdClass
    {
        $session = DB::connection(config('moavi.connection'))->table('sync_sessions')
            ->where('id', $sessionId)
            ->where('api_client_id', $client->id)
            ->where('status', 'active')
            ->where('expires_at', '>', $this->now())
            ->first();

        if (!$session) {
            throw new RuntimeException('Sessao de sincronizacao invalida ou expirada.');
        }

        return $session;
    }

    private function sessionByKey(string $syncKeyHash, stdClass $client): stdClass
    {
        $session = DB::connection(config('moavi.connection'))->table('sync_sessions')
            ->where('sync_key_hash', $syncKeyHash)
            ->where('api_client_id', $client->id)
            ->where('status', 'active')
            ->where('expires_at', '>', $this->now())
            ->first();

        if (!$session) {
            throw new RuntimeException('sync_key invalida ou expirada.');
        }

        return $session;
    }

    private function assertCursor(
        array $payload,
        string $kind,
        stdClass $client,
        string $endpoint,
        Carbon $start,
        Carbon $end
    ): void {
        if (
            ($payload['kind'] ?? null) !== $kind ||
            (int) ($payload['client_id'] ?? 0) !== (int) $client->id ||
            ($payload['endpoint'] ?? null) !== $endpoint ||
            ($payload['period_start'] ?? null) !== $start->toDateString() ||
            ($payload['period_end'] ?? null) !== $end->toDateString()
        ) {
            throw new RuntimeException('Cursor nao pertence a esta consulta.');
        }
    }

    private function assertChangeCursor(array $payload, stdClass $client, string $syncKeyHash): void
    {
        if (
            ($payload['kind'] ?? null) !== 'changes' ||
            (int) ($payload['client_id'] ?? 0) !== (int) $client->id ||
            ($payload['sync_key_hash'] ?? null) !== $syncKeyHash
        ) {
            throw new RuntimeException('Cursor nao pertence a esta consulta.');
        }
    }

    private function timezone(): string
    {
        return (string) config('moavi.timezone', 'America/Sao_Paulo');
    }

    private function now(): string
    {
        return Carbon::now($this->timezone())->format('Y-m-d H:i:s');
    }
}
