<?php

namespace App\Http\Controllers;

use App\Models\VistoriaSeguranca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator; // Adicione este import

class VistoriaSegurancaController extends Controller
{
    /**
     * Armazena uma nova Vistoria de Segurança no banco de dados.
     * Rota: POST /api/vistorias-seguranca
     */
    public function index(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        // MODIFICAÇÃO ESTÁ AQUI: Adicione .with('arquivos')
        $vistorias = VistoriaSeguranca::with('arquivos') // <--- ADICIONE ESTA LINHA
            ->whereBetween('created_at', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($vistorias);
    }
    public function store(Request $request)
    {
        // 1. Validação de todos os campos do formulário
        $validator = Validator::make($request->all(), [
            // Dados de Identificação
            'inspetor_id' => 'required|exists:users,id',
            'regional_id' => 'required|exists:regionais,id',
            'cidade' => 'required|string|max:255',
            'nome_tecnico' => 'required|string|max:255',
            'empresa_id' => 'required|exists:empresas,id',
            'modo_despache' => 'required|string|max:255',
            'tecnico_no_local' => 'required|in:Sim,Não',
            'atividade_externa' => 'required|in:Sim,Não',
            'cpf_tecnico' => 'required|string|max:20',
            'nome_supervisor' => 'required|string|max:255',
            'placa' => 'required|string|max:15',

            // Checklist de EPIs e Segurança
            'uso_capacete' => 'required|in:Sim,Não',
            'uso_cinto' => 'required|in:Sim,Não',
            'uso_talabarte' => 'required|in:Sim,Não',
            'uso_botas' => 'required|in:Sim,Não',
            'escada_estavel' => 'required|in:Sim,Não',
            'escada_amarrada' => 'required|in:Sim,Não',
            'sinalizacao_cones' => 'required|in:Sim,Não',
            'escada_bom_estado' => 'required|in:Sim,Não',

            // Arquivos e Observações
            'observacoes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();

        // Garante que o usuário logado é o mesmo inspetor enviado
        if ($validatedData['inspetor_id'] != Auth::id()) {
            return response()->json(['message' => 'ID do inspetor inválido.'], 403);
        }

        DB::beginTransaction();
       
            // ==========================================================
            // ==================== A CORREÇÃO ESTÁ AQUI ==================
            // ==========================================================
            // Cria a vistoria usando todos os dados validados, EXCETO a chave 'arquivos'.
            $vistoria = VistoriaSeguranca::create(
                collect($validatedData)->except('arquivos')->toArray()
            );
            // ==========================================================

            // Processa e armazena cada arquivo enviado
            if ($request->hasFile('arquivos')) {
                foreach ($request->file('arquivos') as $file) {
                    $path = $file->store('vistorias_seguranca', 'public');
                    $vistoria->arquivos()->create(['path' => $path]);
                }
            }

            DB::commit();
            return response()->json($vistoria->load('arquivos'), 201);

      
    }
    public function upload(Request $request, VistoriaSeguranca $vistoria)
    {
        $validator = Validator::make($request->all(), [
            'arquivo' => 'required|string', // Base64
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($vistoria->inspetor_id != Auth::id()) {
            return response()->json(['message' => 'Não autorizado a adicionar arquivos a esta vistoria.'], 403);
        }

        try {

            $base64 = $request->arquivo;

            // remove prefixo tipo "data:image/jpeg;base64,"
            if (str_contains($base64, ',')) {
                [$meta, $fileData] = explode(',', $base64);
            } else {
                $fileData = $base64;
            }

            $fileData = base64_decode($fileData);

            // Detectar extensão pela metadata
            $extension = 'jpg';
            if (str_contains($meta, 'png')) $extension = 'png';
            if (str_contains($meta, 'pdf')) $extension = 'pdf';
            if (str_contains($meta, 'jpeg')) $extension = 'jpeg';

            // Nome do arquivo
            $filename = uniqid('vistoria_') . '.' . $extension;

            // Salvar no storage
            $path = "vistorias_seguranca/$filename";
            Storage::disk('public')->put($path, $fileData);

            // Salvar no banco
            $vistoria->arquivos()->create(['path' => $path]);

            return response()->json(['message' => 'Arquivo enviado com sucesso.', 'path' => $path], 200);

        } catch (\Exception $e) {
            Log::error("Erro upload base64 → {$e->getMessage()}");
            return response()->json(['message' => 'Erro interno no upload.'], 500);
        }
    }

}