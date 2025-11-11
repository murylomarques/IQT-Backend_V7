<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage; // Importe a classe Storage

class VistoriaSegurancaArquivo extends Model
{
    use HasFactory;

    protected $table = 'vistoria_seguranca_arquivos';
    protected $fillable = ['vistoria_seguranca_id', 'path'];

    /**
     * Adiciona atributos extras à representação JSON do modelo.
     * Isso fará com que 'full_url' seja incluído automaticamente na resposta da API.
     *
     * @var array
     */
    protected $appends = ['full_url'];

    /**
     * Define a relação inversa: um Arquivo pertence a uma Vistoria.
     */
    public function vistoriaSeguranca(): BelongsTo
    {
        return $this->belongsTo(VistoriaSeguranca::class, 'vistoria_seguranca_id');
    }

    /**
     * Acessor para criar uma URL completa para o arquivo.
     * O nome do método DEVE ser get[NomeDoAtributo]Attribute.
     *
     * @return string
     */
    public function getFullUrlAttribute(): string
    {
        // Isso transforma "vistorias_seguranca/imagem.jpg" em
        // "http://127.0.0.1:8000/storage/vistorias_seguranca/imagem.jpg"
        return Storage::url($this->path);
    }
}