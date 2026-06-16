<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaseManutencao extends Model
{
    use HasFactory;

    protected $table = 'base_manutencao';

    protected $fillable = [
        'caso',
        'numero_compromisso',
        'regional',
        'city',
        'data_sa_concluida',
        'nome_tecnico',
        'empresa_tecnico',
        'tipo_servico',
        'motivo_vistoria',
        'tipo_trabalho',
        'status_caso',
        'cto',
        'porta',
        'nome_conta',
        'endereco',
        'telefone',
        'territorio',
    ];
}
