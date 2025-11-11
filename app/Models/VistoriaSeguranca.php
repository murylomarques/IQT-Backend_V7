<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // NECESSÁRIO importar BelongsTo

class VistoriaSeguranca extends Model
{
    use HasFactory;

    /**
     * O nome da tabela associada com o modelo.
     *
     * @var string
     */
    protected $table = 'vistorias_seguranca';

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'inspetor_id', 'regional_id', 'cidade', 'nome_tecnico', 'empresa_id',
        'modo_despache', 'tecnico_no_local', 'atividade_externa', 'cpf_tecnico',
        'nome_supervisor', 'placa', 'uso_capacete', 'uso_cinto', 'uso_talabarte',
        'uso_botas', 'escada_estavel', 'escada_amarrada', 'sinalizacao_cones',
        'escada_bom_estado', 'observacoes',
    ];

    /**
     * Define a relação: uma Vistoria de Segurança tem muitos Arquivos.
     */
    public function arquivos(): HasMany
    {
        return $this->hasMany(VistoriaSegurancaArquivo::class);
    }

    /**
     * Define a relação inversa: uma Vistoria pertence a um Inspetor (que é um User).
     */
    public function inspetor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspetor_id');
    }

    /**
     * Define a relação inversa: uma Vistoria pertence a uma Regional.
     * ESTA É UMA DAS CORREÇÕES NECESSÁRIAS.
     */
    public function regional(): BelongsTo
    {
        // O segundo argumento 'regional_id' diz ao Laravel qual coluna usar para a junção.
        return $this->belongsTo(Regional::class, 'regional_id');
    }

    /**
     * Define a relação inversa: uma Vistoria pertence a uma Empresa.
     * ESTA É A OUTRA CORREÇÃO NECESSÁRIA.
     */
    public function empresa(): BelongsTo
    {
        // O segundo argumento 'empresa_id' diz ao Laravel qual coluna usar para a junção.
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}