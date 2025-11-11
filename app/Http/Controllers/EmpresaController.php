<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmpresaController extends Controller
{
    /**
     * Lista todas as empresas.
     */
    public function index()
    {
        return Empresa::latest()->get();
    }

    /**
     * Cria uma nova empresa.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255|unique:empresas,nome',
            // Adicione outras validações se a tabela 'empresas' tiver mais campos (cnpj, endereco, etc.)
        ]);

        $empresa = Empresa::create($validated);
        return response()->json($empresa, 201);
    }

    /**
     * Exibe uma empresa específica.
     */
    public function show(Empresa $empresa)
    {
        return $empresa;
    }

    /**
     * Atualiza uma empresa existente.
     */
    public function update(Request $request, Empresa $empresa)
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:255', Rule::unique('empresas')->ignore($empresa->id)],
        ]);

        $empresa->update($validated);
        return response()->json($empresa);
    }

    /**
     * Deleta uma empresa.
     */
    public function destroy(Empresa $empresa)
    {
        // Impede a exclusão se houver usuários associados a esta empresa
        if ($empresa->users()->count() > 0) {
            return response()->json(['message' => 'Não é possível deletar uma empresa que possui usuários vinculados.'], 409); // 409 Conflict
        }

        $empresa->delete();
        return response()->json(null, 204);
    }
}