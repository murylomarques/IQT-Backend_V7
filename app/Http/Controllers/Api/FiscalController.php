<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Agenda;
use Illuminate\Http\Request;

class FiscalController extends Controller
{
    /**
     * Retorna uma lista de todos os usuários que são fiscais.
     */
    public function index()
    {
        // Busca usuários onde o cargo (relacionado) tem o nome 'Fiscal'
        // Altere 'Fiscal' se o nome do cargo no seu banco for diferente.
        $fiscais = User::whereHas('cargo', function ($query) {
            $query->where('nome', 'Fiscal');
        })
        ->select('id', 'nome') // Seleciona apenas os campos necessários para o dropdown
        ->get();

        return response()->json($fiscais);
    }

    /**
     * Retorna a agenda de um fiscal específico.
     */
    public function showAgenda($fiscalId)
    {
        // Busca na tabela 'agenda' todos os registros de um fiscal específico
        $agenda = Agenda::where('fiscal_id', $fiscalId)
            ->select(
                'id',
                'data_agendamento',
                'periodo',
                'status'
            )
            ->get();

        return response()->json($agenda);
    }
}