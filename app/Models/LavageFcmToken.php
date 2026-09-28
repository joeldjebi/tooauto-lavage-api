<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LavageFcmToken extends Model
{
    use HasFactory;

    protected $table = 'lavage_fcm_tokens';

    protected $fillable = [
        'lavage_id',
        'token',
        'platform',
        'device_id',
        'last_used_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'lavage_id' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function lavage()
    {
        return $this->belongsTo(Lavage::class, 'lavage_id');
    }
}
