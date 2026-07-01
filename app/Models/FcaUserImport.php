<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcaUserImport extends Model
{
    protected $fillable = [
        'label',
        'uploaded_by',
        'source_filename',
        'rows_count',
        'is_active',
    ];

    public function rows()
    {
        return $this->hasMany(FcaUserImportRow::class);
    }

    public function uploader()
    {
        return $this->belongsTo(FcaUser::class, 'uploaded_by');
    }
}
