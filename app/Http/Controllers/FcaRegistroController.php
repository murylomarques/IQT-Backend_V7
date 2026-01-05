<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FcaRegistro;
use App\Models\FcaUser;

class FcaRegistroController extends Controller
{
    // 🔒 Função para validar o token manualmente (sem Sanctum)
    private function getUserFromToken(Request $request)
    {
        $token = $request->header('Authorization');
        if (!$token) {
            return null;
        }

        return FcaUser::where('token', $token)->first();
    }
     // =================================================================
    // ✅ MÉTODO CORRIGIDO PARA O COORDENADOR
    // (Busca registros apenas dos supervisores diretos do coordenador logado)
    // =================================================================
    public function indexCoordenador(Request $request)
    {
        $coordenador = $this->getUserFromToken($request);
        if (!$coordenador) {
            return response()->json(['error' => 'Token inválido ou ausente.'], 401);
        }

        // Validação para garantir que o usuário tem o cargo de Coordenador.
        // Isso previne que um supervisor tente acessar esta rota.
        if (strcasecmp($coordenador->cargo, 'Coordenador') !== 0) {
            return response()->json(['error' => 'Acesso não autorizado para este cargo.'], 403);
        }

        // 1. Busca os IDs de todos os usuários (Supervisores)
        //    que têm o ID do coordenador logado na coluna 'nivel_hierarquia'.
        $supervisorIds = FcaUser::where('nivel_hierarquia', $coordenador->id)->pluck('id');

        // Se este coordenador não gerencia nenhum supervisor, retorna uma lista vazia.
        if ($supervisorIds->isEmpty()) {
            return response()->json([]);
        }

        // 2. Busca todos os registros onde o 'id_supervisor' está na lista de IDs encontrada.
        //    O método with('supervisor') otimiza a consulta para já trazer os dados do supervisor.
        $registros = FcaRegistro::with('supervisor')
            ->whereIn('id_supervisor', $supervisorIds)
            ->orderBy('data_inicio', 'desc')
            ->get();
        
        // 3. Formata a resposta para incluir o nome do supervisor em cada registro,
        //    conforme o front-end precisa para o filtro e a tabela.
        $formattedRegistros = $registros->map(function ($registro) {
            return [
                'id' => $registro->id,
                'id_supervisor' => $registro->id_supervisor,
                'nome_supervisor' => $registro->supervisor->nome ?? 'Supervisor Desconhecido',
                'nome_tecnico' => $registro->nome_tecnico,
                'fato' => $registro->fato,
                'causa' => $registro->causa,
                'acao' => $registro->acao,
                'status' => $registro->status,
                'responsavel' => $registro->responsavel,
                'data_inicio' => $registro->data_inicio,
                'data_fim' => $registro->data_fim,
                'realizado' => $registro->realizado,
                'created_at' => $registro->created_at,
                'updated_at' => $registro->updated_at,
            ];
        });

        return response()->json($formattedRegistros);
    }

    // 📋 Listar registros apenas do supervisor logado
    public function index(Request $request)
    {
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json(['error' => 'Token inválido ou ausente.'], 401);
        }

        $registros = FcaRegistro::where('id_supervisor', $user->id)
            ->orderBy('data_inicio', 'desc')
            ->get();

        return response()->json($registros);
    }

    // ➕ Criar novo registro
    public function store(Request $request)
    {
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json(['error' => 'Token inválido ou ausente.'], 401);
        }

        $validated = $request->validate([
            'nome_tecnico' => 'required|string|max:150',
            'fato' => 'required|string',
            'causa' => 'required|string',
            'acao' => 'required|string',
            'status' => 'in:Pendente,Em Execução,Concluído,Vencido',
            'responsavel' => 'nullable|string|max:255',
            'data_inicio' => 'required|date',
            'data_fim' => 'nullable|date',
            'realizado' => 'boolean',
        ]);

        $registro = FcaRegistro::create([
            ...$validated,
            'id_supervisor' => $user->id
        ]);

        return response()->json([
            'message' => 'Registro criado com sucesso!',
            'registro' => $registro
        ], 201);
    }

    // ✏️ Atualizar um registro
    public function update(Request $request, $id)
{
    $user = $this->getUserFromToken($request);
    if (!$user) {
        return response()->json(['error' => 'Token inválido ou ausente.'], 401);
    }

    $registro = FcaRegistro::where('id_supervisor', $user->id)->find($id);
    if (!$registro) {
        return response()->json(['error' => 'Registro não encontrado.'], 404);
    }

    // Pega os dados do request, exceto 'realizado'
    $data = $request->only(['fato', 'causa', 'acao', 'status', 'responsavel', 'data_fim']);

    // Atualiza 'realizado' para 1 sempre
    $data['realizado'] = 1;

    $registro->update($data);

    return response()->json([
        'message' => 'Registro atualizado com sucesso.',
        'registro' => $registro
    ]);
}



    // ❌ Deletar registro
    public function destroy(Request $request, $id)
    {
        $user = $this->getUserFromToken($request);
        if (!$user) {
            return response()->json(['error' => 'Token inválido ou ausente.'], 401);
        }

        $registro = FcaRegistro::where('id_supervisor', $user->id)->find($id);
        if (!$registro) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $registro->delete();
        return response()->json(['message' => 'Registro excluído com sucesso.']);
    }
     public function indexAll(Request $request)
    {
        $user = $this->getUserFromToken($request);
        if (!$user || $user->cargo !== 'Administrador') { // Proteção
            return response()->json(['error' => 'Acesso não autorizado.'], 403);
        }

        $registros = FcaRegistro::with('supervisor')
            ->orderBy('data_inicio', 'desc')
            ->get();
        
        $formattedRegistros = $registros->map(function ($registro) {
            return [
                'id' => $registro->id,
                'nome_supervisor' => $registro->supervisor->nome ?? 'N/A',
                'nome_tecnico' => $registro->nome_tecnico,
                'fato' => $registro->fato,
                'causa' => $registro->causa,
                'acao' => $registro->acao, 
                'status' => $registro->status, 
                'responsavel' => $registro->responsavel,
                'data_inicio' => $registro->data_inicio,
                'data_fim' => $registro->data_fim,
            ];
        });

        return response()->json($formattedRegistros);
    }

}
