<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ==========================================================
// ================ IMPORTAÇÕES QUE FALTAVAM ================
// ==========================================================
use App\Models\Cargo;
use App\Models\Empresa;
// ==========================================================


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Os atributos que podem ser atribuídos em massa.
     * CORRIGIDO para corresponder à sua migration.
     */
    protected $fillable = [
        'nome', // Corrigido de 'name' para 'nome'
        'email',
        'password',
        'numero',
        'cpf',
        'cadastro_completo',
        'imagem_perfil',
        'supervisor_id',
        'status',
        'empresa_id',
        'cargo_id',
        'regional_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Relação: um Usuário pertence a um Cargo.
     */
    public function cargo(): BelongsTo
    {
        // O Laravel vai procurar a chave estrangeira 'cargo_id' na tabela 'users'
        return $this->belongsTo(Cargo::class);
    }

    /**
     * Relação: um Usuário pertence a uma Empresa.
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    // Você pode adicionar outras relações aqui, como supervisor e regional, se precisar.
}