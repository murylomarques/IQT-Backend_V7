<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcaPeriodTecnico extends Model
{
    public const REQUIRED_CERTIFICADO = 1;
    public const REQUIRED_NAO_CERTIFICADO = 3;
    public const MIN_HOURS_BETWEEN_SAME_TYPE = 72;

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
        return $this->isCertificado() ? self::REQUIRED_CERTIFICADO : self::REQUIRED_NAO_CERTIFICADO;
    }
}
