<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcaChecklist extends Model
{
    protected $fillable = ['fca_period_id', 'supervisor_id', 'tecnico_id', 'answers'];

    protected $casts = ['answers' => 'array'];

    public function supervisor()
    {
        return $this->belongsTo(FcaUser::class, 'supervisor_id');
    }

    public function tecnico()
    {
        return $this->belongsTo(FcaPeriodTecnico::class, 'tecnico_id');
    }
}
