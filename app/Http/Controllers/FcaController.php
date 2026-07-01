<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use App\Models\FcaUser;
use App\Models\FcaUserImport;
use App\Models\FcaUserImportRow;
use App\Models\FcaLinkRequest;
use App\Models\FcaMonthlyWindowConfig;

class FcaController extends Controller
{
    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function login(Request $request)
    {
        $request->validate([
            'usuario'  => 'required|string',
            'password' => 'required|string',
        ]);

        $user = FcaUser::where('usuario', $request->usuario)
            ->orWhere('email', $request->usuario)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Usuário ou senha inválidos.'], 401);
        }

        $token = hash('sha256', uniqid(rand(), true));
        $user->token = $token;
        $user->save();

        return response()->json([
            'token'      => $token,
            'id'         => $user->id,
            'name'       => $user->name,
            'role'       => $user->role,
            'territory'  => $user->territory,
            'regional'   => $user->regional,
            'manager_id' => $user->manager_id,
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->attributes->get('fca_user');
        return response()->json($this->formatUser($user));
    }

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    public function dashboard(Request $request)
    {
        $user = $request->attributes->get('fca_user');

        $allUsers = FcaUser::all();

        $metrics = [
            'total'          => $allUsers->count(),
            'consulta'       => $allUsers->where('role', 'consulta')->count(),
            'tecnico'        => $allUsers->where('role', 'tecnico')->count(),
            'supervisao'     => $allUsers->where('role', 'supervisao')->count(),
            'coordenacao'    => $allUsers->where('role', 'coordenacao')->count(),
            'nao_vinculados' => $allUsers->whereIn('role', ['tecnico', 'supervisao'])->whereNull('manager_id')->count(),
        ];

        // Visible users depends on role
        // For supervisors and coordinators, also include unlinked users
        // so the "Vincular" tab can show them in the dropdown
        $visibleUsers = match ($user->role) {
            'admin', 'consulta' => $allUsers,

            'coordenacao' => FcaUser::where('manager_id', $user->id)->get()
                ->concat(FcaUser::whereIn('manager_id', FcaUser::where('manager_id', $user->id)->pluck('id'))->get())
                ->concat(FcaUser::where('role', 'supervisao')->whereNull('manager_id')->get())
                ->unique('id'),

            'supervisao' => FcaUser::where('manager_id', $user->id)
                ->orWhere(fn($q) => $q->where('role', 'tecnico')->whereNull('manager_id'))
                ->get(),

            default => collect([$user]),
        };

        return response()->json([
            'metrics'      => $metrics,
            'visible_users' => $visibleUsers->map(fn($u) => $this->formatUser($u))->values(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Monthly Window
    // -------------------------------------------------------------------------

    public function getWindow(Request $request)
    {
        $config = FcaMonthlyWindowConfig::first() ?? ['start_day' => 1, 'end_day' => 7];
        $status = $this->resolveWindowStatus($config);
        return response()->json(['config' => $config, 'status' => $status]);
    }

    public function updateWindow(Request $request)
    {
        $request->validate([
            'start_day' => 'required|integer|min:1|max:31',
            'end_day'   => 'required|integer|min:1|max:31|gte:start_day',
        ]);

        $config = FcaMonthlyWindowConfig::firstOrNew([]);
        $config->start_day = $request->start_day;
        $config->end_day   = $request->end_day;
        $config->save();

        return response()->json(['message' => 'Janela atualizada.', 'config' => $config]);
    }

    private function resolveWindowStatus($config): array
    {
        $now       = now();
        $startDay  = is_array($config) ? $config['start_day'] : $config->start_day;
        $endDay    = is_array($config) ? $config['end_day']   : $config->end_day;
        $day       = (int) $now->format('j');
        $isOpen    = $day >= $startDay && $day <= $endDay;

        if ($isOpen) {
            $windowEnd    = $now->copy()->setDay($endDay)->endOfDay();
            $nextChangeAt = $windowEnd;
        } else {
            $nextMonth    = $day > $endDay ? $now->copy()->addMonth() : $now->copy();
            $nextChangeAt = $nextMonth->setDay($startDay)->startOfDay();
        }

        return [
            'is_open'       => $isOpen,
            'next_change_at' => $nextChangeAt->toIso8601String(),
            'start_day'     => $startDay,
            'end_day'       => $endDay,
        ];
    }

    // -------------------------------------------------------------------------
    // User Management (admin only)
    // -------------------------------------------------------------------------

    public function indexUsers(Request $request)
    {
        $users = FcaUser::with('manager:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        return response()->json($users);
    }

    public function createUser(Request $request)
    {
        $request->validate([
            'name'           => 'required|string|max:255',
            'usuario'        => 'required|string|unique:fca_users,usuario',
            'email'          => 'nullable|email|unique:fca_users,email',
            'password'       => 'required|string|min:6',
            'role'           => 'required|in:admin,coordenacao,supervisao,tecnico,consulta',
            'employee_id'    => 'nullable|string|max:50',
            'cpf'            => 'nullable|string|max:20',
            'empresa'        => 'nullable|string|max:150',
            'territory'      => 'nullable|string|max:100',
            'regional'       => 'nullable|string|max:100',
            'title'          => 'nullable|string|max:150',
            'manager_id'     => 'nullable|integer|exists:fca_users,id',
            'data_admissao'  => 'nullable|date',
            'data_demissao'  => 'nullable|date',
            'observacao'     => 'nullable|string',
        ]);

        $user = FcaUser::create([
            'name'           => $request->name,
            'usuario'        => $request->usuario,
            'email'          => $request->email,
            'password'       => Hash::make($request->password),
            'role'           => $request->role,
            'employee_id'    => $request->employee_id,
            'cpf'            => $request->cpf,
            'empresa'        => $request->empresa,
            'territory'      => $request->territory,
            'regional'       => $request->regional,
            'title'          => $request->title,
            'manager_id'     => $request->manager_id,
            'data_admissao'  => $request->data_admissao,
            'data_demissao'  => $request->data_demissao,
            'observacao'     => $request->observacao,
        ]);

        return response()->json(['message' => 'Usuário criado.', 'user' => $this->formatUser($user)], 201);
    }

    public function updateUser(Request $request, $id)
    {
        $user = FcaUser::findOrFail($id);

        $actor = $request->attributes->get('fca_user');

        $request->validate([
            'name'          => 'sometimes|string|max:255',
            'usuario'       => 'sometimes|string|unique:fca_users,usuario,' . $id,
            'email'         => 'sometimes|nullable|email|unique:fca_users,email,' . $id,
            'password'      => 'sometimes|string|min:6',
            'role'          => 'sometimes|in:admin,coordenacao,supervisao,tecnico,consulta',
            'employee_id'   => 'sometimes|nullable|string|max:50',
            'cpf'           => 'sometimes|nullable|string|max:20',
            'empresa'       => 'sometimes|nullable|string|max:150',
            'territory'     => 'sometimes|nullable|string|max:100',
            'regional'      => 'sometimes|nullable|string|max:100',
            'title'         => 'sometimes|nullable|string|max:150',
            'manager_id'    => 'sometimes|nullable|integer|exists:fca_users,id',
            'data_admissao' => 'sometimes|nullable|date',
            'data_demissao' => 'sometimes|nullable|date',
            'observacao'    => 'sometimes|nullable|string',
        ]);

        $data = $request->only(['name', 'usuario', 'email', 'role', 'employee_id', 'cpf', 'empresa', 'territory', 'regional', 'title', 'manager_id', 'data_admissao', 'observacao']);

        // data_demissao só pode ser editada por admin
        if ($actor->role === 'admin' && $request->has('data_demissao')) {
            $data['data_demissao'] = $request->data_demissao;
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return response()->json(['message' => 'Usuário atualizado.', 'user' => $this->formatUser($user->fresh())]);
    }

    public function deleteUser(Request $request, $id)
    {
        $actor = $request->attributes->get('fca_user');

        if ((int) $actor->id === (int) $id) {
            return response()->json(['error' => 'Não é possível excluir a própria conta.'], 422);
        }

        $user = FcaUser::findOrFail($id);
        FcaUser::where('manager_id', $id)->update(['manager_id' => null]);
        $user->delete();

        return response()->json(['message' => 'Usuário excluído.']);
    }

    // -------------------------------------------------------------------------
    // CSV Import / Export
    // -------------------------------------------------------------------------

    public function importCsv(Request $request)
    {
        // Remove limite de tempo — importar 4000+ usuários leva mais de 30s
        set_time_limit(0);

        $request->validate(['file' => 'required|file']);

        $path      = $request->file('file')->getRealPath();
        $firstLine = file_get_contents($path, false, null, 0, 2048);
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $handle  = fopen($path, 'r');
        $rawHdrs = fgetcsv($handle, 0, $delimiter);
        if (!$rawHdrs) {
            fclose($handle);
            return response()->json(['message' => 'Arquivo vazio ou inválido.', 'created' => 0, 'updated' => 0, 'errors' => []]);
        }

        $headers    = array_map(fn($h) => $this->normalizeHeader($h), $rawHdrs);
        $numHeaders = count($headers);

        // Pré-carrega IDs existentes em memória: evita N+1 queries no loop
        $byEmpId   = FcaUser::whereNotNull('employee_id')->pluck('id', 'employee_id')->toArray();
        $byUsuario = FcaUser::pluck('id', 'usuario')->toArray();

        $toInsert = [];   // novos usuários
        $toUpdate = [];   // [id => payload]
        $snapshotSources = [];
        $errors   = [];
        $now      = now()->toDateTimeString();
        $rowNumber = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            if (count($row) < $numHeaders) continue;

            $raw = array_combine($headers, array_map('trim', array_slice($row, 0, $numHeaders)));
            if (!is_array($raw)) continue;

            try {
                $matricula  = $raw['matricula']   ?? $raw['usuario']    ?? '';
                $nome       = $this->firstRawValue($raw, ['colaborador', 'nome', 'name', 'tecnico', 'supervisor', 'coordenador', 'gerente']) ?? '';
                $cpfRaw     = trim($raw['cpf']     ?? '');
                $cpf        = $cpfRaw !== '' ? $cpfRaw : null;
                $senhaCpf   = preg_replace('/\D/', '', $cpfRaw);
                $cargo      = $this->firstRawValue($raw, ['cargo', 'title', 'role']) ?? '';
                $email      = (!empty($raw['email'])) ? trim($raw['email']) : null;
                $territorio = (!empty($raw['territorio'])) ? $raw['territorio'] : ((!empty($raw['territory'])) ? $raw['territory'] : null);
                $regional   = (!empty($raw['regional'])) ? $raw['regional'] : null;
                $empresa    = (!empty($raw['empresa'])) ? $raw['empresa'] : null;
                $empId      = (!empty($raw['employee_id'])) ? $raw['employee_id'] : ($matricula ?: null);
                $usuario    = $matricula ?: $empId;
                $role       = $this->inferRole($cargo);

                if (empty($usuario) && empty($nome)) continue;

                // Busca em memória — O(1), sem queries extras
                $existingId = ($empId ? ($byEmpId[$empId] ?? null) : null)
                           ?? ($usuario ? ($byUsuario[$usuario] ?? null) : null);

                if ($existingId === -1) continue;

                $payload = [
                    'name'        => $nome,
                    'usuario'     => $usuario,
                    'email'       => $email,
                    'role'        => $role,
                    'employee_id' => $empId,
                    'cpf'         => $cpf,
                    'empresa'     => $empresa,
                    'territory'   => $territorio,
                    'regional'    => $regional,
                    'title'       => ($cargo !== '') ? $cargo : null,
                ];

                if (array_key_exists('observacao', $raw)) {
                    $payload['observacao'] = trim((string) $raw['observacao']) !== '' ? trim((string) $raw['observacao']) : null;
                }

                $snapshotSources[] = [
                    'source_row' => $rowNumber,
                    'raw' => $raw,
                    'payload' => $payload,
                ];

                if ($existingId) {
                    $toUpdate[$existingId] = $payload;
                } else {
                    // Evita duplicatas dentro do mesmo arquivo
                    if (($empId && isset($byEmpId[$empId])) || ($usuario && isset($byUsuario[$usuario]))) continue;

                    // rounds=8 é 4× mais rápido que o padrão (10) e seguro para senha temporária
                    $payload['password']   = Hash::make($senhaCpf ?: 'Mudar@123', ['rounds' => 8]);
                    $payload['created_at'] = $now;
                    $payload['updated_at'] = $now;
                    $toInsert[] = $payload;

                    // Marca no índice local para não inserir duplicata do mesmo arquivo
                    if ($empId)   $byEmpId[$empId]     = -1;
                    if ($usuario) $byUsuario[$usuario]  = -1;
                }
            } catch (\Throwable $e) {
                $errors[] = ($raw['colaborador'] ?? $raw['name'] ?? 'linha') . ': ' . $e->getMessage();
            }
        }

        fclose($handle);

        $created = 0;
        $updated = 0;

        // Insert em lotes de 200 (evita query gigante)
        foreach (array_chunk($toInsert, 200) as $chunk) {
            try {
                FcaUser::insert($chunk);
                $created += count($chunk);
            } catch (\Throwable $e) {
                // Fallback: tenta um por um para identificar o registro problemático
                foreach ($chunk as $item) {
                    try {
                        FcaUser::insert([$item]);
                        $created++;
                    } catch (\Throwable $ie) {
                        $errors[] = ($item['name'] ?? 'linha') . ': ' . $ie->getMessage();
                    }
                }
            }
        }

        // Updates individuais (payloads diferentes por registro)
        foreach ($toUpdate as $id => $payload) {
            try {
                FcaUser::where('id', $id)->update($payload);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = ($payload['name'] ?? 'ID '.$id) . ': ' . $e->getMessage();
            }
        }

        $import = null;
        if (count($snapshotSources) > 0) {
            try {
                $import = $this->storeImportSnapshot($request, $snapshotSources);
            } catch (\Throwable $e) {
                $errors[] = 'snapshot: ' . $e->getMessage();
            }
        }

        return response()->json([
            'message' => "Importação concluída. Criados: {$created}, Atualizados: {$updated}." . (count($errors) ? " Erros: " . count($errors) . "." : ''),
            'created' => $created,
            'updated' => $updated,
            'import'  => $import ? $this->formatImport($import) : null,
            'errors'  => $errors,
        ]);
    }

    public function clearImported(Request $request)
    {
        // Deleta base operacional e preserva acessos admin/consulta.
        $ids = FcaUser::whereNotIn('role', ['admin', 'consulta'])->pluck('id');
        FcaUser::whereIn('manager_id', $ids)->update(['manager_id' => null]);
        $deleted = FcaUser::whereNotIn('role', ['admin', 'consulta'])->delete();

        return response()->json(['message' => "Base limpa. {$deleted} usuário(s) removido(s)."]);
    }

    public function importHistory(Request $request)
    {
        return response()->json(
            FcaUserImport::with('uploader:id,name')
                ->latest()
                ->take(36)
                ->get()
                ->map(fn($import) => $this->formatImport($import))
                ->values()
        );
    }

    public function exportCsv(Request $request)
    {
        $lines = [];
        $lines[] = $this->csvLine($this->ghExportHeaders());

        if ($request->filled('import_id')) {
            $import = FcaUserImport::findOrFail($request->query('import_id'));

            FcaUserImportRow::where('fca_user_import_id', $import->id)
                ->orderBy('source_row')
                ->orderBy('id')
                ->chunk(500, function ($rows) use (&$lines) {
                    foreach ($rows as $row) {
                        $lines[] = $this->csvLine($this->snapshotExportRow($row));
                    }
                });

            return response(implode("\n", $lines), 200, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="usuarios_gh_hierarquia_periodo_' . $import->id . '_' . now()->format('Ymd') . '.csv"',
            ]);
        }

        $users = FcaUser::with('manager.manager.manager')
            ->orderBy('name')
            ->get();

        foreach ($users as $u) {
            $lines[] = $this->csvLine($this->currentExportRow($u));
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="usuarios_gh_hierarquia_' . now()->format('Ymd') . '.csv"',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function storeImportSnapshot(Request $request, array $snapshotSources): FcaUserImport
    {
        $actor = $request->attributes->get('fca_user');
        $label = trim((string) $request->input('label', ''));
        if ($label === '') {
            $label = $this->inferImportLabel($snapshotSources) ?? now()->format('m/Y');
        }

        $import = FcaUserImport::create([
            'label' => $label,
            'uploaded_by' => $actor?->id,
            'source_filename' => $request->file('file')?->getClientOriginalName(),
            'rows_count' => 0,
        ]);

        $employeeIds = collect($snapshotSources)
            ->map(fn($source) => $source['payload']['employee_id'] ?? null)
            ->filter()
            ->unique()
            ->values();
        $usuarios = collect($snapshotSources)
            ->map(fn($source) => $source['payload']['usuario'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $usersByEmpId = FcaUser::with('manager.manager.manager')
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('employee_id');
        $usersByUsuario = FcaUser::with('manager.manager.manager')
            ->whereIn('usuario', $usuarios)
            ->get()
            ->keyBy('usuario');

        $rows = [];
        $now = now()->toDateTimeString();

        foreach ($snapshotSources as $source) {
            $payload = $source['payload'];
            $raw = $source['raw'];
            $user = ($payload['employee_id'] ?? null) ? $usersByEmpId->get($payload['employee_id']) : null;
            $user = $user ?: (($payload['usuario'] ?? null) ? $usersByUsuario->get($payload['usuario']) : null);
            $hierarchy = $user ? $this->resolveHierarchyColumns($user) : [
                'tecnico' => '',
                'supervisor' => '',
                'coordenador' => '',
                'gerente' => '',
                'hierarquia_completa' => '',
            ];

            $rows[] = [
                'fca_user_import_id' => $import->id,
                'fca_user_id' => $user?->id,
                'source_row' => $source['source_row'] ?? null,
                'name' => $payload['name'] ?? $user?->name,
                'usuario' => $payload['usuario'] ?? $user?->usuario,
                'email' => $payload['email'] ?? $user?->email,
                'role' => $payload['role'] ?? $user?->role,
                'employee_id' => $payload['employee_id'] ?? $user?->employee_id,
                'cpf' => $payload['cpf'] ?? $user?->cpf,
                'empresa' => $payload['empresa'] ?? $user?->empresa,
                'territory' => $payload['territory'] ?? $user?->territory,
                'regional' => $payload['regional'] ?? $user?->regional,
                'title' => $payload['title'] ?? $user?->title,
                'tecnico' => $this->firstRawValue($raw, ['tecnico']) ?? $hierarchy['tecnico'],
                'supervisor' => $this->firstRawValue($raw, ['supervisor']) ?? $hierarchy['supervisor'],
                'coordenador' => $this->firstRawValue($raw, ['coordenador']) ?? $hierarchy['coordenador'],
                'gerente' => $this->firstRawValue($raw, ['gerente']) ?? $hierarchy['gerente'],
                'hierarquia_completa' => $this->firstRawValue($raw, ['hierarquia_completa']) ?? $hierarchy['hierarquia_completa'],
                'usuario_created_at' => $this->firstRawValue($raw, ['created_at']) ?? $this->formatExportDate($user?->created_at),
                'observacao' => array_key_exists('observacao', $payload) ? $payload['observacao'] : ($user?->observacao),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            FcaUserImportRow::insert($chunk);
        }

        $import->rows_count = count($rows);
        $import->save();

        return $import->fresh('uploader:id,name');
    }

    private function formatImport(FcaUserImport $import): array
    {
        return [
            'id' => $import->id,
            'label' => $import->label,
            'source_filename' => $import->source_filename,
            'rows_count' => $import->rows_count,
            'uploaded_by' => $import->uploaded_by,
            'uploaded_by_name' => $import->relationLoaded('uploader') ? ($import->uploader->name ?? null) : null,
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }

    private function ghExportHeaders(): array
    {
        return [
            'id',
            'usuario',
            'email',
            'role',
            'employee_id',
            'cpf',
            'territory',
            'regional',
            'title',
            'tecnico',
            'supervisor',
            'coordenador',
            'GERENTE',
            'hierarquia_completa',
            'created_at',
            'observacao',
        ];
    }

    private function currentExportRow(FcaUser $user): array
    {
        $hierarchy = $this->resolveHierarchyColumns($user);

        return [
            $user->id,
            $user->usuario ?? '',
            $user->email ?? '',
            $user->role ?? '',
            $user->employee_id ?? '',
            $user->cpf ?? '',
            $user->territory ?? '',
            $user->regional ?? '',
            $user->title ?? '',
            $hierarchy['tecnico'],
            $hierarchy['supervisor'],
            $hierarchy['coordenador'],
            $hierarchy['gerente'],
            $hierarchy['hierarquia_completa'],
            $this->formatExportDate($user->created_at),
            $user->observacao ?? '',
        ];
    }

    private function snapshotExportRow(FcaUserImportRow $row): array
    {
        return [
            $row->fca_user_id ?? '',
            $row->usuario ?? '',
            $row->email ?? '',
            $row->role ?? '',
            $row->employee_id ?? '',
            $row->cpf ?? '',
            $row->territory ?? '',
            $row->regional ?? '',
            $row->title ?? '',
            $row->tecnico ?? '',
            $row->supervisor ?? '',
            $row->coordenador ?? '',
            $row->gerente ?? '',
            $row->hierarquia_completa ?? '',
            $row->usuario_created_at ?? '',
            $row->observacao ?? '',
        ];
    }

    private function formatExportDate($value): string
    {
        if (!$value) return '';

        try {
            return $value instanceof \DateTimeInterface
                ? $value->format('d/m/Y')
                : \Carbon\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    private function firstRawValue(array $raw, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && trim((string) $raw[$key]) !== '') {
                return trim((string) $raw[$key]);
            }
        }

        return null;
    }

    private function inferImportLabel(array $snapshotSources): ?string
    {
        foreach ($snapshotSources as $source) {
            $raw = $source['raw'] ?? [];
            if (!is_array($raw)) continue;

            $value = $this->firstRawValue($raw, ['competencia', 'mes', 'periodo', 'data_base', 'created_at']);
            $label = $this->monthLabelFromValue($value);
            if ($label) return $label;
        }

        return null;
    }

    private function monthLabelFromValue(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/', $value, $m)) {
            $year = (int) $m[3];
            if ($year < 100) $year += 2000;
            $month = (int) $m[2];
            if ($month >= 1 && $month <= 12) return sprintf('%02d/%04d', $month, $year);
        }

        if (preg_match('/\b(\d{1,2})[\/\-](\d{2,4})\b/', $value, $m)) {
            $year = (int) $m[2];
            if ($year < 100) $year += 2000;
            $month = (int) $m[1];
            if ($month >= 1 && $month <= 12) return sprintf('%02d/%04d', $month, $year);
        }

        $months = [
            'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4,
            'mai' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
            'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12,
        ];
        if (preg_match('/\b(jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)[a-z]*[\/\- ]?(\d{2,4})\b/i', $value, $m)) {
            $year = (int) $m[2];
            if ($year < 100) $year += 2000;
            return sprintf('%02d/%04d', $months[strtolower(substr($m[1], 0, 3))], $year);
        }

        try {
            return \Carbon\Carbon::parse($value)->format('m/Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveHierarchyColumns(FcaUser $user): array
    {
        $tecnico = '';
        $supervisor = '';
        $coordenador = '';
        $gerente = '';
        $hierarquiaCompleta = '';

        if ($user->role === 'tecnico') {
            $tecnico = $user->name;
            $manager = $user->manager;
            $coordinatorUser = null;

            if ($manager?->role === 'supervisao') {
                $supervisor = $manager->name;

                if ($manager->manager?->role === 'coordenacao') {
                    $coordinatorUser = $manager->manager;
                    $coordenador = $coordinatorUser->name;
                }
            } elseif ($manager?->role === 'coordenacao') {
                $coordinatorUser = $manager;
                $coordenador = $manager->name;
            }

            $gerente = $coordinatorUser?->manager?->name ?? '';
            $hierarquiaCompleta = ($tecnico && $supervisor && $coordenador) ? 'Sim' : 'Nao';
        } elseif ($user->role === 'supervisao') {
            $supervisor = $user->name;
            $coordinatorUser = null;

            if ($user->manager?->role === 'coordenacao') {
                $coordinatorUser = $user->manager;
                $coordenador = $coordinatorUser->name;
            }

            $gerente = $coordinatorUser?->manager?->name ?? '';
            $hierarquiaCompleta = ($supervisor && $coordenador) ? 'Sim' : 'Nao';
        } elseif ($user->role === 'coordenacao') {
            $coordenador = $user->name;
            $gerente = $user->manager?->name ?? '';
            $hierarquiaCompleta = 'Sim';
        }

        return [
            'tecnico' => $tecnico,
            'supervisor' => $supervisor,
            'coordenador' => $coordenador,
            'gerente' => $gerente,
            'hierarquia_completa' => $hierarquiaCompleta,
        ];
    }

    private function csvLine(array $fields): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $fields, ';', '"', '');
        rewind($handle);
        $line = rtrim(stream_get_contents($handle), "\r\n");
        fclose($handle);

        return $line;
    }

    private function formatUser(FcaUser $user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'usuario'        => $user->usuario,
            'email'          => $user->email,
            'role'           => $user->role,
            'employee_id'    => $user->employee_id,
            'cpf'            => $user->cpf,
            'empresa'        => $user->empresa,
            'territory'      => $user->territory,
            'regional'       => $user->regional,
            'title'          => $user->title,
            'manager_id'     => $user->manager_id,
            'manager_name'   => $user->relationLoaded('manager') ? ($user->manager->name ?? null) : null,
            'data_admissao'  => $user->data_admissao,
            'data_demissao'  => $user->data_demissao,
            'observacao'     => $user->observacao,
            'created_at'     => $user->created_at?->format('Y-m-d'),
        ];
    }

    private function inferRole(string $raw): string
    {
        $lower = strtolower($raw);
        if (str_contains($lower, 'admin'))       return 'admin';
        if (str_contains($lower, 'coord'))       return 'coordenacao';
        if (str_contains($lower, 'super'))       return 'supervisao';
        if (str_contains($lower, 'tecn'))        return 'tecnico';
        return 'consulta';
    }

    private function normalizeHeader(string $h): string
    {
        // Remove UTF-8 BOM (EF BB BF) de qualquer forma
        $h = str_replace("\xEF\xBB\xBF", '', $h);
        $h = trim($h);

        // Se não for UTF-8 válido (ex: Windows-1252), converte para UTF-8
        if (!mb_check_encoding($h, 'UTF-8')) {
            $h = mb_convert_encoding($h, 'UTF-8', 'Windows-1252');
        }

        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
            'Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a',
            'É'=>'e','Ê'=>'e','Í'=>'i',
            'Ó'=>'o','Õ'=>'o','Ô'=>'o',
            'Ú'=>'u','Ç'=>'c','Ñ'=>'n',
        ];
        $h = strtr($h, $map);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h);
        if ($ascii !== false) {
            $h = $ascii;
        }

        $h = strtolower(trim($h));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);

        return trim($h, '_');
    }
}
