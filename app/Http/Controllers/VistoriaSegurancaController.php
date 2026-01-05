<?php

namespace App\Http\Controllers;

use App\Models\VistoriaSeguranca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VistoriaSegurancaController extends Controller
{
    /**
     * Lista as vistorias existentes com base em um intervalo de datas.
     */
    public function index(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $vistorias = VistoriaSeguranca::with('arquivos', 'inspetor', 'regional', 'empresa')
            ->whereBetween('created_at', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($vistorias);
    }

    /**
     * ETAPA 1: Cria um novo registro de Vistoria de Segurança SEM arquivos.
     * Recebe os dados do formulário via JSON.
     */
    public function store(Request $request)
    {
        // Validação SEM a regra de arquivos, pois eles virão em outra requisição.
        $validator = Validator::make($request->all(), [
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
            'uso_capacete' => 'required|in:Sim,Não',
            'uso_cinto' => 'required|in:Sim,Não',
            'uso_talabarte' => 'required|in:Sim,Não',
            'uso_botas' => 'required|in:Sim,Não',
            'escada_estavel' => 'required|in:Sim,Não',
            'escada_amarrada' => 'required|in:Sim,Não',
            'sinalizacao_cones' => 'required|in:Sim,Não',
            'escada_bom_estado' => 'required|in:Sim,Não',
            'observacoes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();

        if ($validatedData['inspetor_id'] != Auth::id()) {
            return response()->json(['message' => 'ID do inspetor inválido.'], 403);
        }

        try {
            // Cria a vistoria apenas com os dados de texto
            $vistoria = VistoriaSeguranca::create($validatedData);
            
            // Retorna a vistoria criada, incluindo seu novo ID, para o frontend usar na Etapa 2
            return response()->json($vistoria, 201);

        } catch (\Exception $e) {
            Log::error('Erro ao criar registro de vistoria: ' . $e->getMessage());
            return response()->json(['message' => 'Ocorreu um erro interno ao criar o registro da vistoria.'], 500);
        }
    }

    /**
     * ETAPA 2: Faz o upload de um arquivo e o associa a uma vistoria existente.
     * Recebe os dados via multipart/form-data.
     */
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