<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Agenda extends Model
{
    use HasFactory;

    protected $table = 'agenda';

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fiscal_id',
        'data_agendamento',
        'hora_agendamento',
        'periodo',
        'observacoes',
        'status', // Adicionado
        'statusAgendamento', // Adicionado
        'statusLaudo', // Adicionado
        'original_atendimento_id',
        'caso',
        'numero_compromisso',
        'city',
        'data_sa_concluida',
        'nome_tecnico',
        'empresa_tecnico',
        'tipo',
        'tipo_trabalho',
        'status_caso',
        'cto',
        'porta',
        'nome_conta',
        'endereco',
        'telefone',
        'territorio',
    ];

    /**
     * Relação com o Fiscal (User).
     */
    public function fiscal()
    {
        return $this->belongsTo(User::class, 'fiscal_id');
    }

    /**
     * Relação com o Atendimento Original.
     */
    public function atendimentoOriginal()
    {
        return $this->belongsTo(BaseSalesforceIntegrada::class, 'original_atendimento_id');
    }
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'data_agendamento' => 'date', // ou 'datetime' se incluir a hora
        'hora_agendamento' => 'datetime:H:i', // Para tratar a hora corretamente
    ];
}