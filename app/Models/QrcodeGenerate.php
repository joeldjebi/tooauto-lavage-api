<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrcodeGenerate extends Model
{
    protected $fillable = [
        'qrcode', 'is_assigned', 'assigned_at'
    ];

    public function assignments()
    {
        return $this->hasMany(QrcodeAssignment::class, 'qrcode_id');
    }
}