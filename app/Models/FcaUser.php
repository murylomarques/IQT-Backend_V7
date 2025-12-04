<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class FcaUser extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'fca_users';

    protected $fillable = [
        'nome',
        'email',
        'usuario',
        'password',
        'cargo',
        'nivel_hierarquia',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
}
