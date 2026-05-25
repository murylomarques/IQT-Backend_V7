<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FcaLinkRequest extends Model
{
    use HasFactory;

    protected $table = 'fca_link_requests';

    protected $fillable = [
        'requester_user_id',
        'parent_user_id',
        'child_user_id',
        'parent_role',
        'child_role',
        'status',
        'requested_at',
        'decided_at',
        'decided_by_user_id',
        'decision_note',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'decided_at'   => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(FcaUser::class, 'requester_user_id');
    }

    public function parent()
    {
        return $this->belongsTo(FcaUser::class, 'parent_user_id');
    }

    public function child()
    {
        return $this->belongsTo(FcaUser::class, 'child_user_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(FcaUser::class, 'decided_by_user_id');
    }
}
