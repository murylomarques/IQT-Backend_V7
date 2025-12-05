<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany; // Importe o HasMany
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class Vistoria extends Model
{
    use HasFactory;
    protected $fillable = ['agenda_id', 'fiscal_id', 'tipo', 'observacoes_gerais', 'status_laudo' ];

    /**
     * Define a relação: uma Vistoria tem muitos itens de checklist.
     * O nome do método DEVE ser exatamente 'checklistItens' para corresponder ao controller.
     */
    public function checklistItens(): HasMany
    {
        return $this->hasMany(VistoriaChecklistItem::class);
    }
     public function fiscal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fiscal_id');
    }

    public function agenda()
    {
        return $this->belongsTo(Agenda::class, 'agenda_id', 'id');
    }


}
