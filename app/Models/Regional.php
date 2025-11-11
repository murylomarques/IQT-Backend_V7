<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Regional extends Model
{
    use HasFactory;

    /**
     * O nome da tabela associada ao model.
     * Esta linha diz ao Laravel para usar a tabela 'regionais' em vez de 'regionals'.
     *
     * @var string
     */
    protected $table = 'regionais';

    /**
     * Os atributos que podem ser atribuídos em massa.
     */
    protected $fillable = ['nome', 'uf'];

    /**
     * Define a relação: uma Regional pode ter vários Usuários.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'regional_id'); // Boa prática especificar a chave estrangeira
    }
}