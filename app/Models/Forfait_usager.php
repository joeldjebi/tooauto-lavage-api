<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Forfait_usager extends Model
{
    use HasFactory;

    protected $table = 'forfait_usagers';

    protected $fillable = [
        'libelle',
        'duree',
        'prix',
        'statut',
        'nombre_vehicule',
        'forfait_avantage_usager_id',
    ];

    protected $casts = [
        'duree' => 'integer',
        'prix' => 'integer',
        'statut' => 'integer',
        'nombre_vehicule' => 'integer',
    ];
}
