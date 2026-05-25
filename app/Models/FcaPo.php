<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcaPo extends Model
{
    protected $fillable = ['fca_period_id', 'supervisor_id', 'tecnico_id', 'answers', 'po_date'];

    protected $casts = [
        'answers' => 'array',
        'po_date' => 'date',
    ];

    public function supervisor()
    {
        return $this->belongsTo(FcaUser::class, 'supervisor_id');
    }

    public function tecnico()
    {
        return $this->belongsTo(FcaPeriodTecnico::class, 'tecnico_id');
    }
}
