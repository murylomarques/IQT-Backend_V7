<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class FcaUser extends Authenticatable
{
    use HasFactory;

    protected $table = 'fca_users';

    protected $fillable = [
        'employee_id',
        'cpf',
        'empresa',
        'name',
        'email',
        'usuario',
        'password',
        'role',
        'manager_id',
        'territory',
        'regional',
        'title',
        'token',
    ];

    protected $hidden = ['password', 'token'];

    public function manager()
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function subordinates()
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
