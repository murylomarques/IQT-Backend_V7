<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcaUserImportRow extends Model
{
    protected $fillable = [
        'fca_user_import_id',
        'fca_user_id',
        'source_row',
        'name',
        'usuario',
        'email',
        'role',
        'employee_id',
        'cpf',
        'empresa',
        'territory',
        'regional',
        'title',
        'tecnico',
        'supervisor',
        'coordenador',
        'gerente',
        'hierarquia_completa',
        'usuario_created_at',
        'observacao',
    ];

    public function import()
    {
        return $this->belongsTo(FcaUserImport::class, 'fca_user_import_id');
    }

    public function user()
    {
        return $this->belongsTo(FcaUser::class, 'fca_user_id');
    }
}
