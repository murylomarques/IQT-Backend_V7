<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcaPeriodTecnico extends Model
{
    protected $fillable = ['fca_period_id', 'nome', 'prod_bruta', 'revisita', 'tec1', 'certificado'];

    public function period()
    {
        return $this->belongsTo(FcaPeriod::class, 'fca_period_id');
    }

    public function checklists()
    {
        return $this->hasMany(FcaChecklist::class, 'tecnico_id');
    }

    public function pos()
    {
        return $this->hasMany(FcaPo::class, 'tecnico_id');
    }

    public function isCertificado(): bool
    {
        return $this->certificado === 'Sim';
    }

    public function requiredPos(): int
    {
        return $this->isCertificado() ? 1 : 5;
    }
}
