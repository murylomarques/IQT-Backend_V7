<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgendaManutencao extends Model
{
    use HasFactory;

    protected $table = 'agenda_manutencao';

    protected $fillable = [
        'fiscal_id',
        'data_agendamento',
        'hora_agendamento',
        'periodo',
        'observacoes',
        'status',
        'statusAgendamento',
        'statusLaudo',
        'tipo',
        'original_atendimento_id',
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

    protected $casts = [
        'data_agendamento' => 'date',
        'hora_agendamento' => 'datetime:H:i',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $agenda): void {
            $agenda->vistorias()->get()->each->delete();
        });
    }

    public function fiscal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fiscal_id');
    }

    public function vistorias(): HasMany
    {
        return $this->hasMany(VistoriaManutencao::class, 'agenda_manutencao_id');
    }
}
