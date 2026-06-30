<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Parrain extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'station_de_lavage_id',
        'station_service_id',
        'commercial_id'
    ];

    protected $casts = [
        'station_de_lavage_id' => 'integer',
        'station_service_id' => 'integer',
        'commercial_id' => 'integer'
    ];

    public function stationLavage()
    {
        return $this->belongsTo(StationLavage::class, 'station_de_lavage_id');
    }

    public function commercial()
    {
        return $this->belongsTo(Lavage::class, 'commercial_id');
    }
}
