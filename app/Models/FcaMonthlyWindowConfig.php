<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FcaMonthlyWindowConfig extends Model
{
    use HasFactory;

    protected $table = 'fca_monthly_window_config';

    protected $fillable = ['start_day', 'end_day'];
}
