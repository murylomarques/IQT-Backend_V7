<?php

namespace App\Models;

use App\Services\EvidenceFileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VistoriaSegurancaArquivo extends Model
{
    use HasFactory;

    // Garanta que o nome da tabela está correto
    protected $table = 'vistoria_seguranca_arquivos';

    protected $fillable = ['vistoria_seguranca_id', 'path'];

    protected static function booted(): void
    {
        static::deleting(function (self $arquivo): void {
            EvidenceFileService::delete($arquivo->path);
        });
    }

    public function inspetor() {
        return $this->belongsTo(User::class, 'inspetor_id');
    }

    public function regional() {
        return $this->belongsTo(Regional::class, 'regional_id');
    }

    public function empresa() {
        return $this->belongsTo(Empresa::class, 'empresa_id');
}
}
