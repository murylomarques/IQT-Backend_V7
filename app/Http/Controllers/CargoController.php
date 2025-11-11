<?php

namespace App\Http\Controllers;

use App\Models\Cargo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CargoController extends Controller
{
    /**
     * Lista todos os cargos.
     * GET /api/admin/cargos
     */
    public function index()
    {
        return Cargo::latest()->get();
    }

    /**
     * Cria um novo cargo.
     * POST /api/admin/cargos
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // O nome do cargo deve ser obrigatório e único na tabela 'cargos'
            'nome' => 'required|string|max:255|unique:cargos,nome',
        ]);

        $cargo = Cargo::create($validated);

        return response()->json($cargo, 201); // 201 = Created
    }

    /**
     * Exibe um cargo específico.
     * GET /api/admin/cargos/{cargo}
     */
    public function show(Cargo $cargo)
    {
        return $cargo;
    }

    /**
     * Atualiza um cargo existente.
     * PUT/PATCH /api/admin/cargos/{cargo}
     */
    public function update(Request $request, Cargo $cargo)
    {
        $validated = $request->validate([
            // Ao atualizar, a regra 'unique' deve ignorar o ID do cargo atual
            'nome' => ['required', 'string', 'max:255', Rule::unique('cargos')->ignore($cargo->id)],
        ]);

        $cargo->update($validated);

        return response()->json($cargo);
    }

    /**
     * Deleta um cargo.
     * DELETE /api/admin/cargos/{cargo}
     */
    public function destroy(Cargo $cargo)
    {
        // Opcional: Adicionar lógica para impedir a exclusão se houver usuários associados
        if ($cargo->users()->count() > 0) {
            return response()->json(['message' => 'Não é possível deletar um cargo que está em uso.'], 409); // 409 = Conflict
        }
        
        $cargo->delete();

        return response()->json(null, 204); // 204 = No Content
    }
}