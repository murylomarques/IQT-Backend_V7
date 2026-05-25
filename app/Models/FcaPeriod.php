<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcaPeriod extends Model
{
    protected $fillable = ['mes', 'uploaded_by', 'expires_at', 'is_active'];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active'  => 'boolean',
    ];

    public function tecnicos()
    {
        return $this->hasMany(FcaPeriodTecnico::class);
    }

    public function uploader()
    {
        return $this->belongsTo(FcaUser::class, 'uploaded_by');
    }

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    public function daysLeft(): int
    {
        return max(0, (int) now()->diffInDays($this->expires_at, false));
    }
}
