<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VistoriaManutencao extends Model
{
    use HasFactory;

    protected $table = 'vistorias_manutencao';

    protected $fillable = [
        'agenda_manutencao_id',
        'fiscal_id',
        'tipo',
        'metros_drop',
        'retorno_tecnico',
        'resultado_final',
        'observacoes_gerais',
        'status_laudo',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $vistoria): void {
            $vistoria->checklistItens()->get()->each->delete();
        });
    }

    public function checklistItens(): HasMany
    {
        return $this->hasMany(VistoriaManutencaoChecklistItem::class, 'vistoria_manutencao_id');
    }

    public function fiscal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fiscal_id');
    }

    public function agenda(): BelongsTo
    {
        return $this->belongsTo(AgendaManutencao::class, 'agenda_manutencao_id');
    }
}
