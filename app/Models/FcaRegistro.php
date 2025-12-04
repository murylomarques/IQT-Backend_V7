<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FcaRegistro extends Model
{
    use HasFactory;

    protected $table = 'fca_registros';

    protected $fillable = [
        'id_supervisor',
        'nome_tecnico',
        'fato',
        'causa',
        'acao',
        'status',
        'responsavel',
        'data_inicio',
        'data_fim',
        'realizado',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'realizado' => 'boolean',
    ];

    public function supervisor()
    {
        return $this->belongsTo(FcaUser::class, 'id_supervisor');
    }
}
