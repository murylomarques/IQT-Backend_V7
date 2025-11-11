<?php

namespace App\Http\Controllers;

use App\Models\Regional;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegionalController extends Controller
{
    /**
     * Exibe uma lista de todas as regionais.
     * Rota: GET /api/admin/regionais
     */
    public function index()
    {
        // Retorna todas as regionais, ordenadas pelas mais recentes
        return Regional::latest()->get();
    }

    /**
     * Armazena uma nova regional no banco de dados.
     * Rota: POST /api/admin/regionais
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255|unique:regionais,nome',
            'uf'   => 'required|string|size:2|uppercase', // Garante 2 caracteres e em maiúsculo
        ]);

        $regional = Regional::create($validated);

        return response()->json($regional, 201); // 201: Created
    }

    /**
     * Exibe os detalhes de uma regional específica.
     * Rota: GET /api/admin/regionais/{regional}
     */
    public function show(Regional $regional)
    {
        // Graças ao "Route Model Binding", o Laravel já encontrou a regional pelo ID
        return $regional;
    }

    /**
     * Atualiza uma regional existente no banco de dados.
     * Rota: PUT/PATCH /api/admin/regionais/{regional}
     */
    public function update(Request $request, Regional $regional)
    {
        $validated = $request->validate([
            // A regra 'unique' deve ignorar o ID da regional atual ao verificar por duplicatas
            'nome' => ['required', 'string', 'max:255', Rule::unique('regionais')->ignore($regional->id)],
            'uf'   => 'required|string|size:2|uppercase',
        ]);

        $regional->update($validated);

        return response()->json($regional);
    }

    /**
     * Remove uma regional do banco de dados.
     * Rota: DELETE /api/admin/regionais/{regional}
     */
    public function destroy(Regional $regional)
    {
        // Lógica de segurança: verifica se a regional está sendo usada por algum usuário
        if ($regional->users()->exists()) {
            return response()->json(
                ['message' => 'Não é possível deletar esta regional, pois ela está associada a um ou mais usuários.'],
                409 // 409: Conflict
            );
        }
        
        $regional->delete();

        return response()->json(null, 204); // 204: No Content (sucesso sem corpo de resposta)
    }
}