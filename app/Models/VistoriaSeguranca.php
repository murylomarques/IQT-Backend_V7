<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VistoriaSeguranca extends Model
{
    use HasFactory;

    protected $table = 'vistorias_seguranca';

    protected $fillable = [
        'inspetor_id',
        'regional_id',
        'empresa_id',
        'cidade',
        'nome_tecnico',
        'cpf_tecnico',
        'nome_supervisor',
        'placa',
        'modo_despache',

        'tecnico_no_local',
        'atividade_externa',

        // ✅ NOVOS
        'motivo_sem_atividade_externa', // se existir no banco
        'tipo_valido',                  // Yes/No

        'uso_capacete',
        'uso_cinto',
        'uso_talabarte',
        'uso_botas',
        'escada_estavel',
        'escada_amarrada',
        'sinalizacao_cones',
        'escada_bom_estado',

        'observacoes',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $vistoria): void {
            $vistoria->arquivos()->get()->each->delete();
        });
    }

    public function arquivos(): HasMany
    {
        return $this->hasMany(VistoriaSegurancaArquivo::class, 'vistoria_seguranca_id');
    }

    public function inspetor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspetor_id');
    }

    public function regional(): BelongsTo
    {
        // ✅ confirme o Model correto: Regional ou RegionalModel
        return $this->belongsTo(Regional::class, 'regional_id');
    }

    public function empresa(): BelongsTo
    {
        // ✅ confirme o Model correto: Empresa ou EmpresaModel
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
