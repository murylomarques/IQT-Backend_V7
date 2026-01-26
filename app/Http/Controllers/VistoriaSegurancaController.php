<?php

namespace App\Http\Controllers;

use App\Models\VistoriaSeguranca;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class VistoriaSegurancaController extends Controller
{
    /**
     * Lista as vistorias existentes com base em um intervalo de datas.
     */
    public function index(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
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
        // ✅ valores aceitos agora
        $opcoes = 'Sim,Não,Não se Aplica';

        $validator = Validator::make($request->all(), [
            'inspetor_id'       => 'required|exists:users,id',
            'regional_id'       => 'required|exists:regionais,id',
            'cidade'            => 'required|string|max:255',
            'nome_tecnico'      => 'required|string|max:255',
            'empresa_id'        => 'required|exists:empresas,id',
            'modo_despache'     => 'required|string|max:255',

            'tecnico_no_local'    => "required|in:$opcoes",
            'atividade_externa'   => "required|in:$opcoes",

            'cpf_tecnico'       => 'required|string|max:20',
            'nome_supervisor'   => 'required|string|max:255',
            'placa'             => 'required|string|max:15',

            'uso_capacete'      => "required|in:$opcoes",
            'uso_cinto'         => "required|in:$opcoes",
            'uso_talabarte'     => "required|in:$opcoes",
            'uso_botas'         => "required|in:$opcoes",
            'escada_estavel'    => "required|in:$opcoes",
            'escada_amarrada'   => "required|in:$opcoes",
            'sinalizacao_cones' => "required|in:$opcoes",
            'escada_bom_estado' => "required|in:$opcoes",

            'observacoes'       => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();

        if ((int)$validatedData['inspetor_id'] !== (int)Auth::id()) {
            return response()->json(['message' => 'ID do inspetor inválido.'], 403);
        }

        try {
            $vistoria = VistoriaSeguranca::create($validatedData);
            return response()->json($vistoria, 201);

        } catch (\Exception $e) {
            Log::error('Erro ao criar registro de vistoria: ' . $e->getMessage());
            return response()->json([
                'message' => 'Ocorreu um erro interno ao criar o registro da vistoria.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ETAPA 2: Faz o upload de um arquivo e o associa a uma vistoria existente.
     * Recebe os dados via multipart/form-data (base64).
     */
    public function upload(Request $request, VistoriaSeguranca $vistoria)
    {
        $validator = Validator::make($request->all(), [
            'arquivo' => 'required|string', // Base64
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ((int)$vistoria->inspetor_id !== (int)Auth::id()) {
            return response()->json(['message' => 'Não autorizado a adicionar arquivos a esta vistoria.'], 403);
        }

        try {
            $base64 = $request->arquivo;

            $meta = '';
            if (str_contains($base64, ',')) {
                [$meta, $fileData] = explode(',', $base64);
            } else {
                $fileData = $base64;
            }

            $fileData = base64_decode($fileData);

            $extension = 'jpg';
            if (str_contains($meta, 'png'))  $extension = 'png';
            if (str_contains($meta, 'pdf'))  $extension = 'pdf';
            if (str_contains($meta, 'jpeg')) $extension = 'jpeg';

            $filename = uniqid('vistoria_') . '.' . $extension;
            $path = "vistorias_seguranca/$filename";

            Storage::disk('public')->put($path, $fileData);

            $vistoria->arquivos()->create(['path' => $path]);

            return response()->json(['message' => 'Arquivo enviado com sucesso.', 'path' => $path], 200);

        } catch (\Exception $e) {
            Log::error("Erro upload base64 → {$e->getMessage()}");
            return response()->json(['message' => 'Erro interno no upload.'], 500);
        }
    }
}
