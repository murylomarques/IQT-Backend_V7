<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use App\Models\FcaUser;
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
            'name'        => 'required|string|max:255',
            'usuario'     => 'required|string|unique:fca_users,usuario',
            'email'       => 'nullable|email|unique:fca_users,email',
            'password'    => 'required|string|min:6',
            'role'        => 'required|in:admin,coordenacao,supervisao,tecnico,consulta',
            'employee_id' => 'nullable|string|max:50',
            'cpf'         => 'nullable|string|max:20',
            'empresa'     => 'nullable|string|max:150',
            'territory'   => 'nullable|string|max:100',
            'regional'    => 'nullable|string|max:100',
            'title'       => 'nullable|string|max:150',
            'manager_id'  => 'nullable|integer|exists:fca_users,id',
        ]);

        $user = FcaUser::create([
            'name'        => $request->name,
            'usuario'     => $request->usuario,
            'email'       => $request->email,
            'password'    => Hash::make($request->password),
            'role'        => $request->role,
            'employee_id' => $request->employee_id,
            'cpf'         => $request->cpf,
            'empresa'     => $request->empresa,
            'territory'   => $request->territory,
            'regional'    => $request->regional,
            'title'       => $request->title,
            'manager_id'  => $request->manager_id,
        ]);

        return response()->json(['message' => 'Usuário criado.', 'user' => $this->formatUser($user)], 201);
    }

    public function updateUser(Request $request, $id)
    {
        $user = FcaUser::findOrFail($id);

        $request->validate([
            'name'        => 'sometimes|string|max:255',
            'usuario'     => 'sometimes|string|unique:fca_users,usuario,' . $id,
            'email'       => 'sometimes|nullable|email|unique:fca_users,email,' . $id,
            'password'    => 'sometimes|string|min:6',
            'role'        => 'sometimes|in:admin,coordenacao,supervisao,tecnico,consulta',
            'employee_id' => 'sometimes|nullable|string|max:50',
            'cpf'         => 'sometimes|nullable|string|max:20',
            'empresa'     => 'sometimes|nullable|string|max:150',
            'territory'   => 'sometimes|nullable|string|max:100',
            'regional'    => 'sometimes|nullable|string|max:100',
            'title'       => 'sometimes|nullable|string|max:150',
            'manager_id'  => 'sometimes|nullable|integer|exists:fca_users,id',
        ]);

        $data = $request->only(['name', 'usuario', 'email', 'role', 'employee_id', 'cpf', 'empresa', 'territory', 'regional', 'title', 'manager_id']);

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
        $request->validate(['file' => 'required|file']);

        $path = $request->file('file')->getRealPath();

        // Auto-detect delimiter (semicolon vs comma)
        $firstLine = file_get_contents($path, false, null, 0, 2048);
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $handle  = fopen($path, 'r');
        $rawHdrs = fgetcsv($handle, 4096, $delimiter);
        if (!$rawHdrs) {
            fclose($handle);
            return response()->json(['message' => 'Arquivo vazio ou inválido.', 'created' => 0, 'updated' => 0, 'errors' => []]);
        }

        $headers    = array_map(fn($h) => $this->normalizeHeader($h), $rawHdrs);
        $numHeaders = count($headers);

        $created = 0;
        $updated = 0;
        $errors  = [];

        while (($row = fgetcsv($handle, 4096, $delimiter)) !== false) {
            if (count($row) < $numHeaders) continue;

            // Combina só as primeiras N colunas (ignora extras)
            $raw = array_combine($headers, array_map('trim', array_slice($row, 0, $numHeaders)));
            if (!is_array($raw)) continue;

            try {
                $matricula   = $raw['matricula']   ?? $raw['usuario']    ?? '';
                $nome        = $raw['colaborador']  ?? $raw['nome']      ?? $raw['name'] ?? '';
                $cpfRaw      = $raw['cpf']          ?? '';
                $cpf         = $cpfRaw !== '' ? $cpfRaw : null;
                // Senha = somente os dígitos do CPF
                $senhaCpf    = preg_replace('/\D/', '', $cpfRaw);
                $cargo       = $raw['cargo']        ?? $raw['title']     ?? $raw['role'] ?? '';
                $email       = (!empty($raw['email'])) ? trim($raw['email']) : null;
                $territorio  = $raw['territorio']   ?? $raw['territory'] ?? null;
                $regional    = (!empty($raw['regional'])) ? $raw['regional'] : null;
                $empresa     = (!empty($raw['empresa'])) ? $raw['empresa'] : null;
                $employee_id = (!empty($raw['employee_id'])) ? $raw['employee_id'] : ($matricula ?: null);
                $role        = $this->inferRole($cargo);

                if (empty($matricula) && empty($nome)) continue;

                // Upsert — queries separadas para evitar OR() vazio (bug SQL)
                $existing = null;
                if ($employee_id) {
                    $existing = FcaUser::where('employee_id', $employee_id)->first();
                }
                if (!$existing && $matricula) {
                    $existing = FcaUser::where('usuario', $matricula)->first();
                }
                if (!$existing && $email) {
                    $existing = FcaUser::where('email', $email)->first();
                }

                $payload = [
                    'name'        => $nome,
                    'usuario'     => $matricula ?: $employee_id,
                    'email'       => $email,
                    'role'        => $role,
                    'employee_id' => $employee_id,
                    'cpf'         => $cpf,
                    'empresa'     => $empresa,
                    'territory'   => ($territorio !== '' && $territorio !== null) ? $territorio : null,
                    'regional'    => $regional,
                    'title'       => ($cargo !== '') ? $cargo : null,
                ];

                if ($existing) {
                    $existing->update($payload);
                    $updated++;
                } else {
                    // Senha = dígitos do CPF; fallback 'Mudar@123' se CPF vazio
                    $payload['password'] = Hash::make($senhaCpf ?: 'Mudar@123');
                    FcaUser::create($payload);
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = ($raw['colaborador'] ?? $raw['name'] ?? 'linha') . ': ' . $e->getMessage();
            }
        }

        fclose($handle);

        return response()->json([
            'message' => "Importação concluída. Criados: {$created}, Atualizados: {$updated}.",
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
        ]);
    }

    public function clearImported(Request $request)
    {
        // Deleta todos os usuários não-admin e desvincula dependências primeiro
        $ids = FcaUser::where('role', '!=', 'admin')->pluck('id');
        FcaUser::whereIn('manager_id', $ids)->update(['manager_id' => null]);
        $deleted = FcaUser::where('role', '!=', 'admin')->delete();

        return response()->json(['message' => "Base limpa. {$deleted} usuário(s) removido(s)."]);
    }

    public function exportCsv(Request $request)
    {
        $users = FcaUser::with('manager:id,name')->orderBy('name')->get();

        $lines   = [];
        $lines[] = implode(',', ['id', 'name', 'usuario', 'email', 'role', 'employee_id', 'cpf', 'territory', 'regional', 'title', 'manager', 'created_at']);

        foreach ($users as $u) {
            $lines[] = implode(',', [
                $u->id,
                '"' . str_replace('"', '""', $u->name) . '"',
                $u->usuario,
                $u->email ?? '',
                $u->role,
                $u->employee_id ?? '',
                $u->cpf ?? '',
                $u->territory ?? '',
                $u->regional ?? '',
                '"' . str_replace('"', '""', $u->title ?? '') . '"',
                '"' . str_replace('"', '""', $u->manager->name ?? '') . '"',
                $u->created_at?->format('Y-m-d'),
            ]);
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="usuarios_fca_' . now()->format('Ymd') . '.csv"',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function formatUser(FcaUser $user): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'usuario'      => $user->usuario,
            'email'        => $user->email,
            'role'         => $user->role,
            'employee_id'  => $user->employee_id,
            'cpf'          => $user->cpf,
            'empresa'      => $user->empresa,
            'territory'    => $user->territory,
            'regional'     => $user->regional,
            'title'        => $user->title,
            'manager_id'   => $user->manager_id,
            'manager_name' => $user->relationLoaded('manager') ? ($user->manager->name ?? null) : null,
            'created_at'   => $user->created_at?->format('Y-m-d'),
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
        return strtolower(strtr($h, $map));
    }
}
