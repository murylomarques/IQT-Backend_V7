<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocationLog extends Model
{
    use HasFactory;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'latitude',
        'longitude',
        'network_type',
        'signal_level',
    ];

    /**
     * Define a relação: um Log de Localização pertence a um Usuário.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
