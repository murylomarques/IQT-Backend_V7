<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationLog; // Importe o modelo
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Para debug (opcional)

class LocationController extends Controller
{
    /**
     * Armazena um novo registro de localização para o usuário autenticado.
     */
    public function store(Request $request)
    {
        // 1. Validar os dados que chegam do aplicativo
        $validatedData = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'network_type' => 'required|string|max:50',
            'signal_level' => 'required|integer',
        ]);

        // 2. Pegar o ID do usuário autenticado (graças ao Sanctum)
        $userId = $request->user()->id;

        // 3. Criar o registro no banco de dados
        $log = LocationLog::create([
            'user_id' => $userId,
            'latitude' => $validatedData['latitude'],
            'longitude' => $validatedData['longitude'],
            'network_type' => $validatedData['network_type'],
            'signal_level' => $validatedData['signal_level'],
        ]);

        // Para debug, você pode ver os dados chegando em storage/logs/laravel.log
        Log::info('Log de localização salvo para o usuário: ' . $userId, $log->toArray());

        // 4. Retornar uma resposta de sucesso
        return response()->json([
            'message' => 'Localização registrada com sucesso!'
        ], 201); // 201 Created é o status HTTP correto
    }
}
