<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FidelitePassage extends Model
{
    use HasFactory;

    protected $table = 'fidelite_passages';

    protected $fillable = [
        'fidelite_id',
        'attribution_vehicule_id',
        'type_lavage_id',
        'source',
        'used_at',
    ];

    protected $casts = [
        'fidelite_id' => 'integer',
        'attribution_vehicule_id' => 'integer',
        'type_lavage_id' => 'integer',
        'used_at' => 'datetime',
    ];

    public function fidelite()
    {
        return $this->belongsTo(Fidelite::class, 'fidelite_id');
    }

    public function attribution()
    {
        return $this->belongsTo(AttributionVehicule::class, 'attribution_vehicule_id');
    }

    public function typeLavage()
    {
        return $this->belongsTo(Type_lavage::class, 'type_lavage_id');
    }
}
