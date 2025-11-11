<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VistoriaChecklistItem extends Model
{
    use HasFactory;

    /**
     * O nome da tabela associada ao model.
     * ESTA LINHA CORRIGE O ERRO.
     *
     * @var string
     */
    protected $table = 'vistoria_checklist_itens'; // Especifique o nome exato da sua tabela

    protected $fillable = [
        'vistoria_id', 'item_key', 'status', 'observacao', 'foto_path',
        'status_correcao', 'observacao_correcao', 'foto_correcao_path' // Adicionados
    ];
    /**
     * Define a relação inversa: um item pertence a uma vistoria.
     */
    public function vistoria(): BelongsTo
    {
        return $this->belongsTo(Vistoria::class);
    }
    
     public function resolverItem(Request $request, VistoriaChecklistItem $item)
    {
        $request->validate([
            'foto_correcao' => 'required|image|max:2048',
            'observacao_correcao' => 'nullable|string|max:1000',
        ]);

        // Salva a nova foto
        $path = $request->file('foto_correcao')->store('correcoes', 'public');

        // Atualiza o item
        $item->update([
            'foto_correcao_path' => $path,
            'observacao_correcao' => $request->observacao_correcao,
            'status_correcao' => 'Em Análise', // Muda o status para o admin avaliar
        ]);

        return response()->json($item);
    }

    // O mesmo se aplica aqui.
    public function avaliarItem(Request $request, VistoriaChecklistItem $item)
    {
        // Garante que apenas um admin possa usar esta rota (exemplo)
        if (Auth::user()->cargo_id != 1) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $request->validate([
            'status' => 'required|in:Aprovado,Reprovado',
        ]);

        $item->update(['status_correcao' => $request->status]);

        // Lógica para finalizar a vistoria se todos os itens foram aprovados
        // ...

        return response()->json($item);
    }
}