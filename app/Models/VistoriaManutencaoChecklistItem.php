<?php

namespace App\Models;

use App\Services\EvidenceFileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VistoriaManutencaoChecklistItem extends Model
{
    use HasFactory;

    protected $table = 'vistoria_manutencao_checklist_itens';

    protected $fillable = [
        'vistoria_manutencao_id',
        'item_key',
        'status',
        'observacao',
        'foto_path',
        'status_correcao',
        'observacao_correcao',
        'foto_correcao_path',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $item): void {
            EvidenceFileService::delete($item->foto_path);
            EvidenceFileService::delete($item->foto_correcao_path);
        });
    }

    public function vistoria(): BelongsTo
    {
        return $this->belongsTo(VistoriaManutencao::class, 'vistoria_manutencao_id');
    }
}
