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
        'inspetor_id', 'regional_id', 'cidade', 'nome_tecnico', 'empresa_id',
        'modo_despache', 'tecnico_no_local', 'atividade_externa', 'cpf_tecnico',
        'nome_supervisor', 'placa', 'uso_capacete', 'uso_cinto', 'uso_talabarte',
        'uso_botas', 'escada_estavel', 'escada_amarrada', 'sinalizacao_cones',
        'escada_bom_estado', 'observacoes',

        // ✅ NOVO
        'tipo_valido',
        'motivo_sem_atividade_externa', // se você estiver salvando isso
    ];

    public function arquivos(): HasMany
    {
        return $this->hasMany(VistoriaSegurancaArquivo::class);
    }

    public function inspetor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspetor_id');
    }

    public function regional(): BelongsTo
    {
        return $this->belongsTo(Regional::class, 'regional_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
