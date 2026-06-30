<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StationLavage extends Model
{
    use HasFactory;

    protected $table = 'station_de_lavages';

    protected $fillable = [
        'name',
        'adresse',
        'contact',
        'longitude',
        'latitude',
        'logo',
        'statut',
        'created_by'
    ];

    protected $casts = [
        'statut' => 'integer',
        'created_by' => 'integer'
    ];

    // Relations
    public function creator()
    {
        return $this->belongsTo(Lavage::class, 'created_by');
    }

    public function parrains()
    {
        return $this->hasMany(Parrain::class, 'station_de_lavage_id');
    }
}
