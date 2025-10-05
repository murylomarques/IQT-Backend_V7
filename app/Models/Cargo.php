<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    use HasFactory;

    protected $table = 'cargos';

    /**
     * Define a relação inversa: Um Cargo pode ter vários Usuários.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }
}